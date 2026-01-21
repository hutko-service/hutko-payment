<?php
/**
 * WooCommerce Blocks integration for hutko payment gateway.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Blocks payment method integration.
 *
 * @since 3.5.0
 */
final class Oplata_Gateway_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment gateway instance.
	 *
	 * @var WC_Gateway_Oplata_Card
	 */
	private $gateway;

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = 'hutko';

	/**
	 * Initialize the payment method type.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_oplata_settings', array() );
		$this->gateway  = new WC_Gateway_Oplata_Card();
	}

	/**
	 * Check if payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Get payment method script handles.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'oplata-blocks-integration',
			plugin_dir_url( __FILE__ ) . '../assets/js/checkout.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			WC_OPLATA_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'oplata-blocks-integration' );
		}

		return array( 'oplata-blocks-integration' );
	}

	/**
	 * Get payment method data for frontend.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title' => $this->gateway->title,
		);
	}
}
