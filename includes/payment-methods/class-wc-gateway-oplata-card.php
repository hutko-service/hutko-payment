<?php
/**
 * Card payment gateway for hutko.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card payment gateway class.
 *
 * @since 3.0.0
 */
class WC_Gateway_Oplata_Card extends WC_Oplata_Payment_Gateway {

	use Oplata_Embedded;
	use Oplata_Hosted;

	/**
	 * Subscriptions compatibility handler.
	 *
	 * @var WC_Oplata_Subscriptions_Compat
	 */
	private $subscriptions;

	/**
	 * Pre-orders compatibility handler.
	 *
	 * @var WC_Oplata_Pre_Orders_Compat
	 */
	private $pre_orders;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'hutko';
		$this->icon               = plugins_url( 'assets/img/oplata_logo_cards.svg', WC_OPLATA_BASE_FILE );
		$this->has_fields         = false;
		$this->method_title       = 'hutko';
		$this->method_description = __( 'Card payments, Apple/Google Pay', 'hutko-payment' );

		$this->supports = array(
			'products',
			'refunds',
			'pre-orders',
			'subscriptions',
			'subscription_reactivation',
			'subscription_cancellation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_suspension',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title                  = $this->get_option( 'title' );
		$this->description            = $this->get_option( 'description' );
		$this->enabled                = $this->get_option( 'enabled' );
		$this->test_mode              = 'yes' === $this->get_option( 'test_mode' );
		$this->merchant_id            = (int) $this->get_option( 'merchant_id' );
		$this->secret_key             = $this->get_option( 'secret_key' );
		$this->integration_type       = $this->get_option( 'integration_type' ) ? $this->get_option( 'integration_type' ) : false;
		$this->redirect_page_id       = $this->get_option( 'redirect_page_id' );
		$this->completed_order_status = $this->get_option( 'completed_order_status' ) ? $this->get_option( 'completed_order_status' ) : false;
		$this->expired_order_status   = $this->get_option( 'expired_order_status' ) ? $this->get_option( 'expired_order_status' ) : false;
		$this->declined_order_status  = $this->get_option( 'declined_order_status' ) ? $this->get_option( 'declined_order_status' ) : false;
		$this->recurrent_payment      = 'yes' === $this->get_option( 'recurrent_payment' );

		parent::__construct();

		if ( class_exists( 'WC_Pre_Orders_Order' ) ) {
			$this->pre_orders = new WC_Oplata_Pre_Orders_Compat( $this );
		}

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			$this->subscriptions = new WC_Oplata_Subscriptions_Compat( $this );
		}
	}

	/**
	 * Display admin options with custom notices.
	 *
	 * @return void
	 */
	public function admin_options() {
		do_action( 'wc_gateway_hutko_admin_options' );
		parent::admin_options();
	}

	/**
	 * Initialize form fields for settings page.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                => array(
				'title'       => __( 'Enable/Disable', 'hutko-payment' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable hutko Gateway', 'hutko-payment' ),
				'default'     => 'no',
				'description' => __( 'Show in the Payment List as a payment option', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'test_mode'              => array(
				'title'       => __( 'Test mode', 'hutko-payment' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode', 'hutko-payment' ),
				'default'     => 'no',
				'description' => __( 'Place the payment gateway in test mode using test Merchant ID', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'title'                  => array(
				'title'       => __( 'Title', 'hutko-payment' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout', 'hutko-payment' ),
				'default'     => __( 'hutko Cards, Apple/Google Pay', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'description'            => array(
				'title'       => __( 'Description:', 'hutko-payment' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely by Credit/Debit Card or by Apple/Google Pay with hutko.', 'hutko-payment' ),
				'description' => __( 'This controls the description which the user sees during checkout', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'merchant_id'            => array(
				'title'       => __( 'Merchant ID', 'hutko-payment' ),
				'type'        => 'text',
				'description' => __( 'Given to Merchant by hutko', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'secret_key'             => array(
				'title'       => __( 'Secret Key', 'hutko-payment' ),
				'type'        => 'text',
				'description' => __( 'Given to Merchant by hutko', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'integration_type'       => array(
				'title'       => __( 'Payment integration type', 'hutko-payment' ),
				'type'        => 'select',
				'options'     => $this->getIntegrationTypes(),
				'description' => __( 'How the payment form will be displayed', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'redirect_page_id'       => array(
				'title'       => __( 'Return Page', 'hutko-payment' ),
				'type'        => 'select',
				'options'     => $this->oplata_get_pages( __( 'Default order page', 'hutko-payment' ) ),
				'description' => __( 'URL of success page', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'completed_order_status' => array(
				'title'       => __( 'Payment completed order status', 'hutko-payment' ),
				'type'        => 'select',
				'options'     => $this->getPaymentOrderStatuses(),
				'default'     => 'none',
				'description' => __( 'The completed order status after successful payment', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'expired_order_status'   => array(
				'title'       => __( 'Payment expired order status', 'hutko-payment' ),
				'type'        => 'select',
				'options'     => $this->getPaymentOrderStatuses(),
				'default'     => 'none',
				'description' => __( 'Order status when payment was expired', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'declined_order_status'  => array(
				'title'       => __( 'Payment declined order status', 'hutko-payment' ),
				'type'        => 'select',
				'options'     => $this->getPaymentOrderStatuses(),
				'default'     => 'none',
				'description' => __( 'Order status when payment was declined', 'hutko-payment' ),
				'desc_tip'    => true,
			),
			'recurrent_payment'      => array(
				'title'       => __( 'Recurrent payment', 'hutko-payment' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Recurrent Payment', 'hutko-payment' ),
				'default'     => 'no',
				'description' => __( 'Request card token for future charges', 'hutko-payment' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Complete payment with pre-order support.
	 *
	 * @param WC_Order $order          Order object.
	 * @param string   $transaction_id Transaction ID.
	 * @return void
	 */
	public function oplataPaymentComplete( $order, $transaction_id ) {
		if ( $this->pre_orders && WC_Pre_Orders_Order::order_contains_pre_order( $order ) ) {
			$order->set_transaction_id( $transaction_id );
			WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order );
		} else {
			parent::oplataPaymentComplete( $order, $transaction_id );
		}
	}

	/**
	 * Process refund.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Refund amount.
	 * @param string $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( empty( $order ) ) {
			return false;
		}

		try {
			$reverse = WC_Oplata_API::reverse(
				array(
					'order_id' => $this->getOplataOrderID( $order ),
					'amount'   => (int) round( $amount * 100 ),
					'currency' => $order->get_currency(),
					'comment'  => substr( $reason, 0, 1024 ),
				)
			);

			switch ( $reverse->reverse_status ) {
				case 'approved':
					return true;

				case 'processing':
					/* translators: %1$s: reverse status */
					$order->add_order_note( sprintf( __( 'Refund hutko status: %1$s', 'hutko-payment' ), $reverse->reverse_status ) );
					return true;

				case 'declined':
					/* translators: %1$s: reverse status */
					$note_text = sprintf( __( 'Refund hutko status: %1$s', 'hutko-payment' ), $reverse->reverse_status );
					$order->add_order_note( $note_text );
					throw new Exception( esc_html( $note_text ) );

				default:
					/* translators: %1$s: reverse status */
					$note_text = sprintf( __( 'Refund hutko status: %1$s', 'hutko-payment' ), 'Unknown' );
					$order->add_order_note( $note_text );
					throw new Exception( esc_html( $note_text ) );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage() );
		}
	}

	/**
	 * Get payment options for embedded widget.
	 *
	 * @return array
	 */
	public function getPaymentOptions() {
		$payment_options = parent::getPaymentOptions();

		$payment_options['methods']          = array( 'card' );
		$payment_options['methods_disabled'] = array();
		$payment_options['active_tab']       = 'card';

		return $payment_options;
	}
}
