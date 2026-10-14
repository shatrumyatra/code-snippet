<?php
/**
 * Region-aware Stripe payment gateway for WooCommerce.
 *
 * - Picks Stripe API credentials per region (see Kv_Payment_Functions).
 * - Stores Stripe fee / net payout on the order for reconciliation.
 *
 */

defined( 'ABSPATH' ) || exit;

// Nothing to do when WooCommerce is not active.
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

// Stripe PHP SDK.
require_once get_template_directory() . '/inc/stripe/vendor/autoload.php';

/**
 * Registers the gateway and loads its front-end assets.
 */
class Kv_Custom_Stripe_Payment {

	const GATEWAY_ID    = 'custom_stripe_payment_gateway';
	const SCRIPT_HANDLE = 'kv-stripe-payment-script';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
	}

	/**
	 * Load Stripe.js and our checkout script on checkout pages only.
	 */
	public function enqueue_scripts() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		$script_path = get_template_directory() . '/assets/js/stripe-payment.js';

		wp_enqueue_script( 'kv-stripe-api-script', 'https://js.stripe.com/v2/', array(), null, true );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			get_template_directory_uri() . '/assets/js/stripe-payment.js',
			array( 'jquery', 'kv-stripe-api-script' ),
			file_exists( $script_path ) ? filemtime( $script_path ) : null, // Cache-bust on change.
			true
		);

		wp_add_inline_script( self::SCRIPT_HANDLE, $this->get_checkout_inline_js() );
	}

	/**
	 * @param string[] $gateways Registered gateway class names.
	 * @return string[]
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'Custom_Stripe_Payment_Gateway';
		return $gateways;
	}

	/**
	 * @return string
	 */
	private function get_checkout_inline_js() {
		return <<<'JS'
	(function ($) {
		var GATEWAY = 'custom_stripe_payment_gateway';

		// Swap WooCommerce's Place Order button for ours while this gateway is selected.
		function syncPlaceOrderButton() {
			var $place = $('#place_order');
			if ($('input[name="payment_method"]:checked').val() === GATEWAY) {
				$place.hide();
				if (!$('#stripe-payment-btn').length) {
					$place.after('<button type="button" class="button alt btn-style btn-red" name="woocommerce_checkout_place_order" id="stripe-payment-btn" value="Place order" data-value="Place order">Place Order</button>');
				}
			} else {
				$('#stripe-payment-btn').remove();
				$place.show();
			}
		}

		$(document.body).on('payment_method_selected updated_checkout', syncPlaceOrderButton);
		$(syncPlaceOrderButton);

		// Card number: digits only, grouped in blocks of four.
		$(document).on('input', '#cardNumber', function () {
			var digits = this.value.replace(/\D/g, '').substring(0, 16);
			this.value = (digits.match(/.{1,4}/g) || []).join(' ');
		});

		// Expiry month / year / CVC: digits only.
		$(document).on('input', '#cardExpMonth, #cardExpYear, #cardCVV', function () {
			this.value = this.value.replace(/\D/g, '');
		});
	})(jQuery);
	JS;
	}
}

new Kv_Custom_Stripe_Payment();

/**
 * The gateway itself.
 */
class Custom_Stripe_Payment_Gateway extends WC_Payment_Gateway {

	/** Currencies Stripe treats as having no minor unit. */
	const ZERO_DECIMAL_CURRENCIES = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
		'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	public function __construct() {
		$this->id                 = Kv_Custom_Stripe_Payment::GATEWAY_ID;
		$this->method_title       = __( 'Custom Stripe Payment Gateway', 'kv-theme' );
		$this->method_description = __( 'Take payments via Stripe Checkout.', 'kv-theme' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title                = $this->get_option( 'title' );
		$this->description          = $this->get_option( 'description' );
		$this->statement_descriptor = $this->get_option( 'statement_descriptor' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_stripe_payment_info' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Enable/Disable', 'kv-theme' ),
				'label'   => __( 'Enable Stripe', 'kv-theme' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title', 'kv-theme' ),
				'type'        => 'text',
				'description' => __( 'This controls the title displayed during checkout.', 'kv-theme' ),
				'default'     => __( 'Pay by Stripe Credit Card', 'kv-theme' ),
				'desc_tip'    => true,
			),
			'description'          => array(
				'title'       => __( 'Description', 'kv-theme' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description displayed during checkout.', 'kv-theme' ),
				'default'     => __( 'Pay using Stripe Payment Gateway', 'kv-theme' ),
				'desc_tip'    => true,
			),
			'statement_descriptor' => array(
				'title'       => __( 'Statement Descriptor', 'kv-theme' ),
				'type'        => 'text',
				'description' => __( 'Statement descriptors explain charges or payments on customer bank statements.', 'kv-theme' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Checkout card form.
	 *
	 * The inputs deliberately have no "name" attribute: WooCommerce serialises named
	 * fields into the checkout POST, which would send raw card data to our server.
	 * Stripe.js reads the data-stripe attributes and returns a token instead.
	 */
	public function payment_fields() {
		$kv_region   = new Kv_Region();
		$credentials = Kv_Payment_Functions::getStripCredentialsByRegion( $kv_region->get_current_region_code() );
		$public_key  = isset( $credentials['stripe_public_key'] ) ? $credentials['stripe_public_key'] : '';

		$fields = array(
			array( 'id' => 'cardNumber',   'stripe' => 'number',    'label' => __( 'Card Number', 'kv-theme' ),        'autocomplete' => 'cc-number',    'maxlength' => 19, 'placeholder' => '' ),
			array( 'id' => 'cardExpMonth', 'stripe' => 'exp_month', 'label' => __( 'Exp. Month', 'kv-theme' ),         'autocomplete' => 'cc-exp-month', 'maxlength' => 2,  'placeholder' => 'MM' ),
			array( 'id' => 'cardExpYear',  'stripe' => 'exp_year',  'label' => __( 'Exp. Year', 'kv-theme' ),          'autocomplete' => 'cc-exp-year',  'maxlength' => 4,  'placeholder' => 'YY' ),
			array( 'id' => 'cardCVV',      'stripe' => 'cvc',       'label' => __( 'Card Code (CVC)', 'kv-theme' ),    'autocomplete' => 'cc-csc',       'maxlength' => 4,  'placeholder' => '' ),
		);

		if ( $this->description ) {
			echo '<p class="form-row stripe-payment-info-section">' . wp_kses_post( $this->description ) . '</p>';
		}
		?>
		<fieldset id="custom-payment-stripe" class="wc-custom-stripe" data-key="<?php echo esc_attr( $public_key ); ?>">
			<?php foreach ( $fields as $field ) : ?>
				<div class="form-row form-row-wide">
					<label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $field['id'] ); ?>"
						data-stripe="<?php echo esc_attr( $field['stripe'] ); ?>"
						inputmode="numeric"
						autocomplete="<?php echo esc_attr( $field['autocomplete'] ); ?>"
						maxlength="<?php echo esc_attr( $field['maxlength'] ); ?>"
						placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
					/>
				</div>
			<?php endforeach; ?>
			<div class="stripe-error"></div>
		</fieldset>
		<?php
	}

	/**
	 * Card details are validated client-side by Stripe.js; the server only needs the token.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$token = $this->get_posted_token();

		if ( '' === $token || 0 !== strpos( $token, 'tok_' ) ) {
			wc_add_notice( __( 'Please check your card details and try again.', 'kv-theme' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Create the Stripe charge and complete the order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found. Please try again.', 'kv-theme' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$token   = $this->get_posted_token();
		$country = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();

		$region_code = Kv_Payment_Functions::getRegionCodeByCountryCode( $country );
		$credentials = Kv_Payment_Functions::getStripCredentialsByRegion( $region_code );
		$secret_key  = isset( $credentials['stripe_secret_key'] ) ? $credentials['stripe_secret_key'] : '';

		if ( '' === $secret_key ) {
			return $this->fail_payment(
				$order,
				sprintf( 'No Stripe secret key configured for region "%s".', $region_code ),
				__( 'Payments are temporarily unavailable. Please contact customer support.', 'kv-theme' )
			);
		}

		$generic_message = __( 'Something went wrong. Please try again, or contact our customer support.', 'kv-theme' );

		try {
			$stripe      = new \Stripe\StripeClient( $secret_key );
			$customer_id = $this->get_or_create_customer( $stripe, $order );

			// Attach the card entered now, so we never charge a stale default card.
			$card = $stripe->customers->createSource( $customer_id, array( 'source' => $token ) );

			$currency = $order->get_currency();

			$charge = $stripe->charges->create(
				array(
					'customer'    => $customer_id,
					'source'      => $card->id,
					'amount'      => self::to_minor_units( $order->get_total(), $currency ),
					'currency'    => strtolower( $currency ),
					'description' => $this->statement_descriptor . ' - ' . $order_id,
					'metadata'    => array( 'order_id' => $order_id ),
					'expand'      => array( 'balance_transaction' ), // Fee + net in the same request.
				),
				// A double-click or network retry with the same token cannot charge twice.
				array( 'idempotency_key' => 'wc_' . $order_id . '_' . $token )
			);
		} catch ( \Stripe\Exception\CardException $e ) {
			// Card errors (declined, expired...) carry a customer-safe message.
			return $this->fail_payment( $order, $e->getMessage(), $e->getMessage() );
		} catch ( \Exception $e ) {
			return $this->fail_payment( $order, $e->getMessage(), $generic_message );
		}

		if ( empty( $charge->paid ) ) {
			return $this->fail_payment( $order, 'Charge was not paid: ' . $charge->id, $generic_message );
		}

		// Fee data is nice-to-have: never fail a successful payment over it.
		try {
			$this->save_stripe_fees( $order, $charge );
		} catch ( \Exception $e ) {
			$this->log( 'Could not save fees for order ' . $order_id . ': ' . $e->getMessage() );
		}

		// Sets the transaction ID, reduces stock and moves the order to processing/completed.
		$order->payment_complete( $charge->id );
		/* translators: %s: Stripe charge ID. */
		$order->add_order_note( sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'kv-theme' ), $charge->id ) );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Show Stripe fee and net payout in the admin order totals.
	 *
	 * @param int $order_id Order ID.
	 */
	public function display_order_stripe_payment_info( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$currency = $order->get_meta( '_stripe_currency' );
		$rows     = array(
			array(
				'class'  => 'stripe-fee',
				'tip'    => __( 'This represents the fee Stripe collects for the transaction.', 'kv-theme' ),
				'label'  => __( 'Stripe Fee:', 'kv-theme' ),
				'amount' => $order->get_meta( '_stripe_fees' ),
				'prefix' => '-',
			),
			array(
				'class'  => 'stripe-payout',
				'tip'    => __( 'This represents the net total that will be credited to your Stripe bank account. This may be in the currency that is set in your Stripe account.', 'kv-theme' ),
				'label'  => __( 'Stripe Payout:', 'kv-theme' ),
				'amount' => $order->get_meta( '_stripe_net_amount' ),
				'prefix' => '',
			),
		);

		foreach ( $rows as $row ) {
			if ( '' === $row['amount'] ) {
				continue;
			}
			?>
			<tr>
				<td class="label <?php echo esc_attr( $row['class'] ); ?>">
					<?php echo wc_help_tip( $row['tip'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core helper, escapes internally. ?>
					<?php echo esc_html( $row['label'] ); ?>
				</td>
				<td width="1%"></td>
				<td class="total">
					<?php echo esc_html( $row['prefix'] ); ?><?php echo wc_price( $row['amount'], array( 'currency' => $currency ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core helper returns safe HTML. ?>
				</td>
			</tr>
			<?php
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @return string Sanitised Stripe token from the checkout POST.
	 */
	private function get_posted_token() {
		// Nonce is verified by WooCommerce before the checkout reaches the gateway.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST['stripeToken'] ) ? sanitize_text_field( wp_unslash( $_POST['stripeToken'] ) ) : '';
	}

	/**
	 * Reuse the Stripe customer with this email, or create one.
	 *
	 * @param \Stripe\StripeClient $stripe Stripe client for the order's region.
	 * @param WC_Order             $order  Order being paid.
	 * @return string Stripe customer ID.
	 */
	private function get_or_create_customer( $stripe, $order ) {
		$email    = $order->get_billing_email();
		$existing = $stripe->customers->all(
			array(
				'email' => $email,
				'limit' => 1,
			)
		);

		if ( ! empty( $existing->data ) ) {
			return $existing->data[0]->id;
		}

		$customer = $stripe->customers->create(
			array(
				'email'   => $email,
				'name'    => trim( $order->get_formatted_billing_full_name() ),
				'address' => array(
					'city'        => $order->get_billing_city(),
					'country'     => $order->get_billing_country(),
					'line1'       => $order->get_billing_address_1(),
					'line2'       => $order->get_billing_address_2(),
					'postal_code' => $order->get_billing_postcode(),
					'state'       => $order->get_billing_state(),
				),
			)
		);

		return $customer->id;
	}

	/**
	 * Store fee, net payout and settlement currency on the order.
	 * Values come from the expanded balance transaction on the charge.
	 *
	 * @param WC_Order       $order  Order.
	 * @param \Stripe\Charge $charge Charge created with expand[]=balance_transaction.
	 */
	private function save_stripe_fees( $order, $charge ) {
		$balance = $charge->balance_transaction;
		if ( ! is_object( $balance ) ) {
			return;
		}

		$currency = strtoupper( $balance->currency );

		$order->update_meta_data( '_stripe_fees', self::from_minor_units( $balance->fee, $currency ) );
		$order->update_meta_data( '_stripe_net_amount', self::from_minor_units( $balance->net, $currency ) );
		$order->update_meta_data( '_stripe_currency', $balance->currency );
		// Saved by payment_complete().
	}

	/**
	 * Log, add an order note and a checkout notice for a failed payment.
	 *
	 * @param WC_Order $order            Order.
	 * @param string   $log_message      Detail for logs and the private order note.
	 * @param string   $customer_message Safe message shown to the customer.
	 * @return array WooCommerce failure result.
	 */
	private function fail_payment( $order, $log_message, $customer_message ) {
		$this->log( 'Order ' . $order->get_id() . ': ' . $log_message );
		$order->add_order_note( 'Stripe error: ' . $log_message );
		wc_add_notice( $customer_message, 'error' );

		return array( 'result' => 'failure' );
	}

	/**
	 * @param string $message Message to write to the WooCommerce log.
	 */
	private function log( $message ) {
		wc_get_logger()->error( $message, array( 'source' => $this->id ) );
	}

	/**
	 * Convert a decimal amount to Stripe's smallest currency unit without float drift.
	 *
	 * @param float|string $amount   Decimal amount.
	 * @param string       $currency ISO currency code.
	 * @return int
	 */
	private static function to_minor_units( $amount, $currency ) {
		$factor = in_array( strtoupper( $currency ), self::ZERO_DECIMAL_CURRENCIES, true ) ? 1 : 100;
		return (int) round( (float) $amount * $factor );
	}

	/**
	 * @param int    $amount   Amount in Stripe's smallest currency unit.
	 * @param string $currency ISO currency code.
	 * @return float
	 */
	private static function from_minor_units( $amount, $currency ) {
		$factor = in_array( strtoupper( $currency ), self::ZERO_DECIMAL_CURRENCIES, true ) ? 1 : 100;
		return $amount / $factor;
	}
}