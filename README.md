# Custom Stripe Payment Gateway for WooCommerce

A WooCommerce payment gateway that takes card payments through Stripe, using a separate set of Stripe API credentials per sales region. It also saves Stripe's fee and net payout on each order so finance can reconcile payments without logging into Stripe.

**File:** `class-kv-custom-stripe-payment.php`

## Requirements

- WordPress with WooCommerce (HPOS compatible)
- PHP 7.4 or newer
- Stripe PHP SDK (7.33+) installed at `inc/stripe/vendor/` in the theme
- Front-end script `assets/js/stripe-payment.js` that creates a Stripe.js v2 token on checkout
- Theme helpers: `Kv_Region` and `Kv_Payment_Functions` (see "Region credentials" in Admin Theme Settings > Stripe Settings > Region credentials)

## Setup

1. Include the file from your theme's `functions.php`:
   ```php
   require_once get_template_directory() . '/inc/class-kv-custom-stripe-payment.php';
   ```
2. In WordPress admin, go to **WooCommerce → Settings → Payments → Custom Stripe Payment Gateway**.
3. Tick **Enable Stripe** and set the title, description and statement descriptor shown to customers.
4. Make sure each region has a Stripe public key and secret key configured.


## Region credentials

The gateway never stores keys itself. It asks two helpers:

| Helper | Used for |
|---|---|
| `Kv_Payment_Functions::getStripCredentialsByRegion( $region )` | Returns `stripe_public_key` and `stripe_secret_key` for a region |
| `Kv_Payment_Functions::getRegionCodeByCountryCode( $country )` | Maps the order's shipping country (billing country as fallback) to a region |

The checkout form uses the **visitor's current region** for the public key on page load. If the customer changes the shipping country, the public key is switched to match the new region. The charge uses the **shipping country's region** for the secret key.
 
The public key and secret key must always belong to the same Stripe account, because a token created with one account's public key can only be charged by that account's secret key.


## How a payment flows

1. At checkout, the customer enters card details. Stripe.js turns them into a one-time token (`stripeToken`). Card numbers are never posted to the server.
2. `validate_fields()` checks that a token was received.
3. `process_payment()` picks the region's secret key, finds or creates the Stripe customer by email, and attaches the new card.
4. It charges the card. A unique ID is sent with the request, so if the customer clicks twice, they are charged only once.”.
5. On success, it saves fee data, calls `payment_complete()` (stock, status, transaction ID), empties the cart and redirects to the thank-you page.
6. On failure, it shows a safe message to the customer and writes the details to the order notes and the WooCommerce log.

## Data stored on the order

| Meta key | Meaning |
|---|---|
| `_stripe_fees` | Stripe's fee, as a decimal amount |
| `_stripe_net_amount` | Amount paid out to your Stripe balance |
| `_stripe_currency` | Currency of those two amounts |
| `_transaction_id` | Stripe charge ID |

Fee and payout appear in the admin order totals.