<?php
/**
 * Embedded payment form integration trait.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for embedded payment form functionality.
 *
 * @since 3.0.0
 */
trait Oplata_Embedded {

	/**
	 * Flag indicating embedded integration is available.
	 *
	 * @var bool
	 */
	public $embedded = true;

	/**
	 * Enqueue scripts and styles for embedded payment form.
	 *
	 * @return void
	 */
	public function includeEmbeddedAssets() {
		if ( 'no' === $this->enabled || ( ! is_cart() && ! is_checkout_pay_page() ) ) {
			return;
		}

		wp_enqueue_style(
			'oplata-vue-css',
			'https://pay.hutko.org/latest/checkout-vue/checkout.css',
			array(),
			WC_OPLATA_VERSION
		);

		if ( ! wp_script_is( 'oplata-vue-js', 'enqueued' ) ) {
			wp_enqueue_script(
				'oplata-vue-js',
				'https://pay.hutko.org/latest/checkout-vue/checkout.js',
				array(),
				WC_OPLATA_VERSION,
				true
			);
		}

		if ( ! wp_script_is( 'oplata-init', 'registered' ) ) {
			wp_register_script(
				'oplata-init',
				plugins_url( 'assets/js/oplata_embedded.js', WC_OPLATA_BASE_FILE ),
				array(),
				WC_OPLATA_VERSION,
				true
			);
		}

		wp_enqueue_style(
			'oplata-embedded',
			plugins_url( 'assets/css/oplata_embedded.css', WC_OPLATA_BASE_FILE ),
			array( 'storefront-woocommerce-style', 'oplata-vue-css' ),
			WC_OPLATA_VERSION
		);
	}

	/**
	 * Display payment form on receipt page.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );

		try {
			$checkout_token = $this->getCheckoutToken( $order );

			$payment_arguments = array(
				'options' => $this->getPaymentOptions(),
				'params'  => array( 'token' => $checkout_token ),
			);
		} catch ( Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		?>
		<script type="text/javascript">
			var oplataPaymentArguments = <?php echo wp_json_encode( $payment_arguments ); ?>;
		</script>
		<?php

		if ( ! wp_script_is( 'oplata-init', 'enqueued' ) ) {
			wp_enqueue_script( 'oplata-init' );
		}

		echo '<div id="oplata-checkout-container"></div>';
	}
}
