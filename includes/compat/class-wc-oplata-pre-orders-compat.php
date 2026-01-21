<?php
/**
 * WooCommerce Pre-Orders compatibility class.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility class for WooCommerce Pre-Orders.
 *
 * @since 3.0.0
 */
class WC_Oplata_Pre_Orders_Compat {

	const META_NAME_OPLATA_ORDER_PREAUTH = '_oplata_order_preauth';

	/**
	 * Payment gateway instance.
	 *
	 * @var WC_Oplata_Payment_Gateway
	 */
	private $paymentGateway;

	/**
	 * Constructor.
	 *
	 * @param WC_Oplata_Payment_Gateway $gateway Payment gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->paymentGateway = $gateway;

		add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->paymentGateway->id, array( $this, 'process_pre_order_payments' ) );
		add_action( 'wc_gateway_oplata_admin_options', array( $this, 'getPreOrdersNotice' ) );
		add_filter( 'wc_gateway_oplata_payment_params', array( $this, 'getPreOrdersPaymentParams' ), 10, 2 );
	}

	/**
	 * Process pre-order payment when order is released.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function process_pre_order_payments( $order ) {
		try {
			$capture = WC_Oplata_API::capture(
				array(
					'order_id' => $order->get_meta( WC_Oplata_Payment_Gateway::META_NAME_HUTKO_ORDER_ID ),
					'currency' => esc_attr( get_woocommerce_currency() ),
					'amount'   => (int) round( $order->get_total() * 100 ),
				)
			);

			if ( 'captured' === $capture->capture_status ) {
				$order->add_order_note( __( 'hutko capture successful.', 'oplata-woocommerce-payment-gateway' ) );
				$order->payment_complete();
			} else {
				/* translators: 1) response status 2) error message 3) request ID */
				throw new Exception(
					sprintf(
						__( 'Transaction: %1$s<br/>%2$s<br/>Request_id: %3$s', 'oplata-woocommerce-payment-gateway' ),
						$capture->response_status,
						$capture->error_message,
						$capture->request_id
					)
				);
			}
		} catch ( Exception $e ) {
			/* translators: %s: error message */
			$order->update_status(
				'failed',
				sprintf(
					__( 'Pre-order payment for order failed. Reason: %s', 'oplata-woocommerce-payment-gateway' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Add preauth parameter for pre-orders.
	 *
	 * @param array    $params Payment parameters.
	 * @param WC_Order $order  Order object.
	 * @return array
	 */
	public function getPreOrdersPaymentParams( $params, $order ) {
		if ( WC_Pre_Orders_Order::order_contains_pre_order( $order ) ) {
			$params['preauth'] = 'Y';
		}

		return $params;
	}

	/**
	 * Display pre-orders notice in admin settings.
	 *
	 * @return void
	 */
	public function getPreOrdersNotice() {
		$message = __( 'Note: transactions by using Pre-Orders must be finished in 7 days term or it will be auto-captured.', 'oplata-woocommerce-payment-gateway' );
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
