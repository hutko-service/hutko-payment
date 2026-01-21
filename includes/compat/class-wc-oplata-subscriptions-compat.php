<?php
/**
 * WooCommerce Subscriptions compatibility class.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility class for WooCommerce Subscriptions.
 *
 * @since 3.0.0
 */
class WC_Oplata_Subscriptions_Compat {

	const META_NAME_OPLATA_RECTOKEN = 'oplata_token';

	/**
	 * Payment gateway instance.
	 *
	 * @var WC_Oplata_Payment_Gateway
	 */
	private $paymentGateway;

	/**
	 * Constructor.
	 *
	 * @param WC_Oplata_Payment_Gateway $paymentGateway Payment gateway instance.
	 */
	public function __construct( $paymentGateway ) {
		$this->paymentGateway = $paymentGateway;

		add_filter( 'wc_gateway_hutko_payment_params', array( $this, 'subscriptionsPaymentParams' ), 10, 2 );
		add_filter( 'wc_gateway_hutko_process_payment_complete', array( $this, 'subscriptionsProcessPaymentComplete' ), 10, 2 );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->paymentGateway->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_action( 'wc_gateway_hutko_receive_valid_callback', array( $this, 'saveToken' ), 10, 2 );
	}

	/**
	 * Modify payment parameters for subscriptions.
	 *
	 * @param array    $params Payment parameters.
	 * @param WC_Order $order  Order object.
	 * @return array
	 */
	public function subscriptionsPaymentParams( $params, $order ) {
		if ( $this->has_subscription( $order ) ) {
			$params['required_rectoken'] = 'Y';

			if ( 0 === (int) $order->get_total() ) {
				$order->add_order_note( __( 'Payment free trial verification', 'oplata-woocommerce-payment-gateway' ) );
				$params['verification'] = 'Y';
				$params['amount']       = 1;
			}
		}

		return $params;
	}

	/**
	 * Process payment completion for subscriptions.
	 *
	 * @param array    $result_data Payment result data.
	 * @param WC_Order $order       Order object.
	 * @return array
	 */
	public function subscriptionsProcessPaymentComplete( $result_data, $order ) {
		global $woocommerce;

		if ( $this->has_subscription( $order ) ) {
			if ( 0 === get_current_user_id() ) {
				wc_add_notice( __( 'You must be logged in.', 'oplata-woocommerce-payment-gateway' ), 'error' );
				return array(
					'result'   => 'fail',
					'redirect' => $woocommerce->cart->get_checkout_url(),
				);
			}
		}

		return $result_data;
	}

	/**
	 * Check if order contains subscription.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public function has_subscription( $order ) {
		return function_exists( 'wcs_order_contains_subscription' ) &&
			( wcs_order_contains_subscription( $order ) || wcs_is_subscription( $order ) || wcs_order_contains_renewal( $order ) );
	}

	/**
	 * Save recurring token from callback.
	 *
	 * @param array    $request_body Callback request body.
	 * @param WC_Order $order        Order object.
	 * @return void
	 */
	public function saveToken( $request_body, $order ) {
		if ( ! empty( $request_body['rectoken'] ) && $this->has_subscription( $order ) ) {
			$user_id = $order->get_user_id();

			$meta_value = array(
				'token'      => $request_body['rectoken'],
				'payment_id' => $this->paymentGateway->id,
			);

			if ( $this->isTokenAlreadySaved( $request_body['rectoken'], $user_id ) ) {
				update_user_meta( $user_id, self::META_NAME_OPLATA_RECTOKEN, $meta_value );
			} else {
				add_user_meta( $user_id, self::META_NAME_OPLATA_RECTOKEN, $meta_value );
			}
		}
	}

	/**
	 * Check if token is already saved for user.
	 *
	 * @param string $token   Recurring token.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	private function isTokenAlreadySaved( $token, $user_id ) {
		$user_tokens = get_user_meta( $user_id, self::META_NAME_OPLATA_RECTOKEN );
		return array_search( $token, array_column( $user_tokens, 'token' ), true );
	}

	/**
	 * Process scheduled subscription payment.
	 *
	 * @param float    $amount_to_charge Amount to charge.
	 * @param WC_Order $renewal_order    Renewal order object.
	 * @return void
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		if ( 0 === $amount_to_charge ) {
			$renewal_order->payment_complete();
			return;
		}

		try {
			$amount      = (int) round( $amount_to_charge * 100 );
			$customer_id = $renewal_order->get_customer_id();

			if ( ! $customer_id ) {
				throw new Exception( __( 'Customer not found.', 'woocommerce' ) );
			}

			$token = get_user_meta( $customer_id, self::META_NAME_OPLATA_RECTOKEN );

			if ( empty( $token ) ) {
				throw new Exception( __( 'Token not found.', 'oplata-woocommerce-payment-gateway' ) );
			}

			if ( $token[0]['payment_id'] !== $this->paymentGateway->id ) {
				throw new Exception( __( 'Token expired, or token not found.', 'oplata-woocommerce-payment-gateway' ) );
			}

			$subscription_payment = WC_Oplata_API::recurring(
				array(
					'order_id'     => $this->paymentGateway->createOplataOrderID( $renewal_order ),
					'amount'       => $amount,
					'rectoken'     => $token[0]['token'],
					'sender_email' => $renewal_order->get_billing_email(),
					'currency'     => get_woocommerce_currency(),
					'order_desc'   => sprintf(
						/* translators: %s: order number */
						__( 'Recurring payment for: %s', 'oplata-woocommerce-payment-gateway' ),
						$renewal_order->get_order_number()
					),
				)
			);

			if ( 'approved' === $subscription_payment->order_status ) {
				$renewal_order->update_status( 'completed' );
				$renewal_order->payment_complete( $subscription_payment->payment_id );
				/* translators: %s: payment ID */
				$renewal_order->add_order_note( sprintf( __( 'hutko subscription payment successful.<br/>hutko ID: %s', 'oplata-woocommerce-payment-gateway' ), $subscription_payment->payment_id ) );
			} else {
				/* translators: 1) order status 2) payment ID */
				throw new Exception( sprintf( __( 'Transaction ERROR: order %1$s<br/>hutko ID: %2$s', 'oplata-woocommerce-payment-gateway' ), $subscription_payment->order_status, $subscription_payment->payment_id ) );
			}
		} catch ( Exception $e ) {
			/* translators: %s: error message */
			$renewal_order->update_status(
				'failed',
				sprintf(
					__( 'Subscription payment failed. Reason: %s', 'oplata-woocommerce-payment-gateway' ),
					$e->getMessage()
				)
			);
		}
	}
}
