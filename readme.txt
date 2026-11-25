=== hutko payment ===
Contributors: hutko
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.7.4
WC requires at least: 5.6
WC tested up to: 9.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Official hutko payment gateway for WooCommerce. Accept Visa, Mastercard, Apple Pay, Google Pay, recurring payments, and installments.

== Description ==

Accept online payments in your WooCommerce store with the official **hutko** payment gateway plugin.

**hutko** is a modern payment service designed for fast, high-conversion checkout. With this plugin, customers can pay using bank cards, digital wallets, or bank installments, while merchants manage refunds, tokenized charges, and automated fiscalization directly from the WordPress admin dashboard.

= Key Features =

* **Payment Methods:** Visa, Mastercard, Prostir, Apple Pay, Google Pay, and "Pay in Parts" installments by PUMB.
* **Flexible Checkout Modes:** Choose between Hosted Checkout (redirect to a PCI DSS-compliant hutko payment page) or Embedded Checkout on your store page.
* **Reliable Order Synchronization:** Authoritative server-to-server callbacks (`server callback`) with cryptographic signature verification automatically update WooCommerce order statuses.
* **One-Click Refunds:** Process full or partial financial refunds straight from the WooCommerce order details screen.
* **Recurring Payments & Tokenization:** Optional card tokenization (`rectoken`) for recurring billing and manual token charges from the order admin panel.
* **Automated Fiscalization (PRRO) Ready:** Transmits order line items and discounts (`reservation_data.products`) to support automated fiscal receipt generation (e.g., Checkbox).
* **Multi-Currency Support:** Process payments in UAH, USD, EUR, and 150+ international currencies.

= External Services =

This plugin connects to the external **hutko Payment Gateway API** (`https://pay.hutko.org/api/` and `https://pay.hutko.org/latest/checkout-vue/`) to process payments, refunds, and transaction status updates.

* **What data is sent and when:** When a customer initiates checkout, or when a store administrator triggers a refund, pre-order capture, or tokenized recurring charge, the plugin sends the Merchant ID, order ID, order amount, currency, order description, line-item details (`reservation_data`), customer billing details/email/phone, return URL (`response_url`), and webhook URL (`server_callback_url`) to `https://pay.hutko.org`.
* **Service Provider:** hutko (https://hutko.org)
* **Terms of Service & Legal Documents:** https://hutko.org/uk/documents/
* **Privacy Policy:** https://hutko.org/uk/documents/

== Installation ==

1. Upload the `hutko-payment` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure settings under WooCommerce > Settings > Payments > hutko.
