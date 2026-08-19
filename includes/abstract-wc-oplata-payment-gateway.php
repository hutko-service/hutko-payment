<?php
/**
 * Abstract payment gateway class for hutko.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Abstract class for hutko payment gateway.
 *
 * @since 3.0.0
 */
abstract class WC_Oplata_Payment_Gateway extends WC_Payment_Gateway {

	const ORDER_APPROVED   = 'approved';
	const ORDER_DECLINED   = 'declined';
	const ORDER_EXPIRED    = 'expired';
	const ORDER_PROCESSING = 'processing';
	const ORDER_CREATED    = 'created';
	const ORDER_REVERSED   = 'reversed';
	const ORDER_SEPARATOR  = '_';

	const META_NAME_HUTKO_ORDER_ID              = '_hutko_order_id';
	const META_NAME_HUTKO_SUCCESSFUL_ORDER_ID   = '_hutko_successful_order_id';
	const META_NAME_HUTKO_SUCCESSFUL_PAYMENT_ID = '_hutko_successful_payment_id';
	const META_NAME_HUTKO_PAYMENT_ATTEMPTS      = '_hutko_payment_attempts';
	const PAYMENT_ATTEMPT_HISTORY_LIMIT         = 20;
	const CALLBACK_LOCK_PREFIX                  = 'hutko_callback_lock_';
	const CALLBACK_LOCK_TTL                     = 60;

	/**
	 * Test mode flag.
	 *
	 * @var bool
	 */
	public $test_mode;

	/**
	 * Merchant ID.
	 *
	 * @var string
	 */
	public $merchant_id;

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Integration type (embedded or hosted).
	 *
	 * @var string
	 */
	public $integration_type;

	/**
	 * Order status after successful payment.
	 *
	 * @var string
	 */
	public $completed_order_status;

	/**
	 * Order status when payment expires.
	 *
	 * @var string
	 */
	public $expired_order_status;

	/**
	 * Order status when payment is declined.
	 *
	 * @var string
	 */
	public $declined_order_status;

	/**
	 * Custom redirect page ID.
	 *
	 * @var int
	 */
	public $redirect_page_id;

	/**
	 * Whether recurrent payment is enabled.
	 *
	 * @var bool
	 */
	public $recurrent_payment = false;

	/**
	 * Whether HPOS is enabled.
	 *
	 * @var bool
	 */
	protected $hpos_in_use;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( $this->test_mode ) {
			$this->merchant_id = WC_Oplata_API::TEST_MERCHANT_ID;
			$this->secret_key  = WC_Oplata_API::TEST_MERCHANT_SECRET_KEY;
		}

		WC_Oplata_API::setMerchantID( $this->merchant_id );
		WC_Oplata_API::setSecretKey( $this->secret_key );

		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'callbackHandler' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		if ( 'embedded' === $this->integration_type ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'includeEmbeddedAssets' ) );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		}

		$this->hpos_in_use = OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $this->recurrent_payment ) {
			add_action( 'add_meta_boxes', array( $this, 'addRecurrentPaymentMetaBox' ) );
			add_action( 'wp_ajax_hutko_recurrent_charge', array( $this, 'ajaxRecurrentCharge' ) );
		}
	}

	/**
	 * Process payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order          = wc_get_order( $order_id );
		$process_result = array(
			'result'   => 'success',
			'redirect' => '',
		);

		try {
			if ( 'embedded' === $this->integration_type ) {
				$process_result['redirect'] = $order->get_checkout_payment_url( true );
			} else {
				$payment_params             = $this->getPaymentParams( $order );
				$process_result['redirect'] = WC_Oplata_API::getCheckoutUrl( $payment_params );
			}
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			$process_result['result'] = 'fail';
		}

		return apply_filters( 'wc_gateway_oplata_process_payment_complete', $process_result, $order );
	}

	/**
	 * Get payment parameters for hutko API.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public function getPaymentParams( $order ) {
		$params = array(
			'order_id'            => $this->createOplataOrderID( $order ),
			'order_desc'          => __( 'Order №: ', 'oplata-woocommerce-payment-gateway' ) . $order->get_id(),
			'amount'              => (int) round( $order->get_total() * 100 ),
			'currency'            => $order->get_currency(),
			'lang'                => $this->getLanguage(),
			'sender_email'        => $this->getEmail( $order ),
			'response_url'        => $this->getResponseUrl( $order ),
			'server_callback_url' => $this->getCallbackUrl(),
			'reservation_data'    => $this->getReservationData( $order ),
		);

		if ( $this->recurrent_payment ) {
			$params['required_rectoken'] = 'Y';
		}

		$params = apply_filters( 'wc_gateway_oplata_payment_params', $params, $order );

		if ( is_array( $params ) ) {
			$this->storePaymentAttempt( $order, $params );
		}

		return $params;
	}

	/**
	 * Generate unique hutko order ID and save it to order meta.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function createOplataOrderID( $order ) {
		$hutko_order_id = $order->get_id() . self::ORDER_SEPARATOR . time();
		$order->update_meta_data( self::META_NAME_HUTKO_ORDER_ID, $hutko_order_id );
		$order->save();

		return $hutko_order_id;
	}

	/**
	 * Get hutko order ID from order meta.
	 *
	 * @param WC_Order $order Order object.
	 * @return mixed
	 */
	public function getOplataOrderID( $order ) {
		return $order->get_meta( self::META_NAME_HUTKO_ORDER_ID );
	}

	/**
	 * Store the signed payment parameters expected for a Hutko attempt.
	 *
	 * @param WC_Order $order  Order object.
	 * @param array    $params Final payment parameters sent to Hutko.
	 * @return void
	 */
	protected function storePaymentAttempt( $order, $params ) {
		if (
			! isset( $params['order_id'], $params['amount'], $params['currency'] ) ||
			! is_scalar( $params['order_id'] ) ||
			! is_scalar( $params['amount'] ) ||
			! is_scalar( $params['currency'] )
		) {
			return;
		}

		$hutko_order_id = (string) $params['order_id'];
		$amount         = (string) $params['amount'];
		$currency       = strtoupper( trim( (string) $params['currency'] ) );

		if ( '' === $hutko_order_id || '' === $currency || ! preg_match( '/^\d+$/', $amount ) ) {
			return;
		}

		// Keep the active attempt aligned with the final, filterable request parameters.
		$order->update_meta_data( self::META_NAME_HUTKO_ORDER_ID, $hutko_order_id );

		$attempts = $order->get_meta( self::META_NAME_HUTKO_PAYMENT_ATTEMPTS );
		$attempts = is_array( $attempts ) ? $attempts : array();

		unset( $attempts[ $hutko_order_id ] );
		$attempts[ $hutko_order_id ] = array(
			'amount'     => (int) $amount,
			'currency'   => $currency,
			'created_at' => time(),
		);

		while ( count( $attempts ) > self::PAYMENT_ATTEMPT_HISTORY_LIMIT ) {
			array_shift( $attempts );
		}

		$order->update_meta_data( self::META_NAME_HUTKO_PAYMENT_ATTEMPTS, $attempts );
		$order->save();
	}

	/**
	 * Get expected parameters for a specific Hutko attempt.
	 *
	 * @param WC_Order $order          Order object.
	 * @param string   $hutko_order_id Full Hutko order ID.
	 * @return array|null
	 */
	protected function getPaymentAttempt( $order, $hutko_order_id ) {
		$attempts = $order->get_meta( self::META_NAME_HUTKO_PAYMENT_ATTEMPTS );

		if ( ! is_array( $attempts ) || ! isset( $attempts[ $hutko_order_id ] ) || ! is_array( $attempts[ $hutko_order_id ] ) ) {
			return null;
		}

		return $attempts[ $hutko_order_id ];
	}

	/**
	 * Get response URL (thank-you page).
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function getResponseUrl( $order ) {
		return $this->redirect_page_id ? get_permalink( $this->redirect_page_id ) : $this->get_return_url( $order );
	}

	/**
	 * Get transaction URL for merchant portal.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		$this->view_transaction_url = 'https://portal.hutko.org/#/transactions/payments/info/%s/general';
		return parent::get_transaction_url( $order );
	}

	/**
	 * Get checkout token and cache it in session.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 * @throws Exception When API call fails.
	 */
	public function getCheckoutToken( $order ) {
		$order_id          = $order->get_id();
		$amount            = (int) round( $order->get_total() * 100 );
		$currency          = $order->get_currency();
		$session_token_key = 'session_token_' . md5( $this->merchant_id . '_' . $order_id . '_' . $amount . '_' . $currency );
		$checkout_token    = WC()->session->get( $session_token_key );

		if ( empty( $checkout_token ) ) {
			$payment_params = $this->getPaymentParams( $order );
			$checkout_token = WC_Oplata_API::getCheckoutToken( $payment_params );
			WC()->session->set( $session_token_key, $checkout_token );
		}

		return $checkout_token;
	}

	/**
	 * Clear checkout token cache from session.
	 *
	 * @param array $payment_params Payment parameters.
	 * @param int   $order_id       Order ID.
	 * @return void
	 */
	public function clearCache( $payment_params, $order_id ) {
		if (
			! isset( $payment_params['amount'], $payment_params['currency'] ) ||
			! is_scalar( $payment_params['amount'] ) ||
			! is_scalar( $payment_params['currency'] ) ||
			! WC()->session
		) {
			return;
		}

		WC()->session->__unset( 'session_token_' . md5( $this->merchant_id . '_' . $order_id . '_' . $payment_params['amount'] . '_' . $payment_params['currency'] ) );
	}

	/**
	 * Get hutko widget options.
	 *
	 * @return array
	 */
	public function getPaymentOptions() {
		return array(
			'full_screen' => false,
			'email'       => true,
		);
	}

	/**
	 * Get site language code (2 characters).
	 *
	 * @return string
	 */
	public function getLanguage() {
		return substr( get_bloginfo( 'language' ), 0, 2 );
	}

	/**
	 * Get customer email for the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function getEmail( $order ) {
		$current_user = wp_get_current_user();
		$email        = $current_user->user_email;

		if ( empty( $email ) ) {
			$order_data = $order->get_data();
			$email      = $order_data['billing']['email'];
		}

		return $email;
	}

	/**
	 * Get callback URL for payment notifications.
	 *
	 * @return string
	 */
	public function getCallbackUrl() {
		return wc_get_endpoint_url( 'wc-api', strtolower( get_class( $this ) ), get_site_url() );
	}

	/**
	 * Get reservation data for anti-fraud purposes.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Base64-encoded JSON.
	 */
	public function getReservationData( $order ) {
		$order_data         = $order->get_data();
		$order_data_billing = $order_data['billing'];

		$reservation_data = array(
			'customer_zip'       => $order_data_billing['postcode'],
			'customer_name'      => $order_data_billing['first_name'] . ' ' . $order_data_billing['last_name'],
			'customer_address'   => $order_data_billing['address_1'] . ' ' . $order_data_billing['city'],
			'customer_state'     => $order_data_billing['state'],
			'customer_country'   => $order_data_billing['country'],
			'phonemobile'        => $order_data_billing['phone'],
			'account'            => $order_data_billing['email'],
			'cms_name'           => 'Wordpress',
			'cms_version'        => get_bloginfo( 'version' ),
			'cms_plugin_version' => WC_OPLATA_VERSION . ' (Woocommerce ' . WC_VERSION . ')',
			'shop_domain'        => get_site_url(),
			'path'               => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'products'           => $this->getReservationDataProducts( $order->get_items() ),
		);

		return base64_encode( wp_json_encode( $reservation_data ) );
	}

	/**
	 * Get product data for reservation.
	 *
	 * @param array $order_items_products Order item products.
	 * @return array
	 */
	public function getReservationDataProducts( $order_items_products ) {
		$reservation_data_products = array();

		try {
			/** @var WC_Order_Item_Product $order_product */
			foreach ( $order_items_products as $order_product ) {
				$quantity   = $order_product->get_quantity();
				$line_total = (float) $order_product->get_total();
				$product    = $order_product->get_product();
				$price      = $quantity > 0 ? $line_total / $quantity : ( $product ? (float) $product->get_price() : 0 );

				$reservation_data_products[] = array(
					'id'           => $order_product->get_product_id(),
					'name'         => $order_product->get_name(),
					'price'        => wc_format_decimal( $price, wc_get_price_decimals() ),
					'total_amount' => wc_format_decimal( $line_total, wc_get_price_decimals() ),
					'quantity'     => $order_product->get_quantity(),
				);
			}
		} catch ( Exception $e ) {
			$reservation_data_products['error'] = $e->getMessage();
		}

		return $reservation_data_products;
	}

	/**
	 * Get available integration types.
	 *
	 * @return array
	 */
	public function getIntegrationTypes() {
		$integration_types = array();

		if ( isset( $this->embedded ) ) {
			$integration_types['embedded'] = __( 'Embedded', 'oplata-woocommerce-payment-gateway' );
		}

		if ( isset( $this->hosted ) ) {
			$integration_types['hosted'] = __( 'Hosted', 'oplata-woocommerce-payment-gateway' );
		}

		return $integration_types;
	}

	/**
	 * Get list of WordPress pages for redirect selection.
	 *
	 * @param string|bool $title  Optional title for first option.
	 * @param bool        $indent Whether to indent child pages.
	 * @return array
	 */
	public function oplata_get_pages( $title = false, $indent = true ) {
		$wp_pages  = get_pages( 'sort_column=menu_order' );
		$page_list = array();

		if ( $title ) {
			$page_list[] = $title;
		}

		foreach ( $wp_pages as $page ) {
			$prefix = '';

			if ( $indent ) {
				$has_parent = $page->post_parent;

				while ( $has_parent ) {
					$prefix    .= ' - ';
					$next_page  = get_post( $has_parent );
					$has_parent = $next_page->post_parent;
				}
			}

			$page_list[ $page->ID ] = $prefix . $page->post_title;
		}

		return $page_list;
	}

	/**
	 * Get available WooCommerce order statuses.
	 *
	 * @return array
	 */
	public function getPaymentOrderStatuses() {
		$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$statuses       = array(
			'default' => __( 'Default status', 'oplata-woocommerce-payment-gateway' ),
		);

		if ( $order_statuses ) {
			foreach ( $order_statuses as $k => $v ) {
				$statuses[ str_replace( 'wc-', '', $k ) ] = $v;
			}
		}

		return $statuses;
	}

	/**
	 * Acquire an atomic per-order callback lock.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array|false Lock data on success, false when another callback owns it.
	 */
	protected function acquireCallbackLock( $order_id ) {
		global $wpdb;

		$lock_name  = self::CALLBACK_LOCK_PREFIX . absint( $order_id );
		$lock_value = time() . '|' . wp_generate_uuid4();
		$created    = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$lock_name,
				$lock_value
			)
		);

		if ( ! $created ) {
			$created = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d",
					$lock_value,
					$lock_name,
					time() - self::CALLBACK_LOCK_TTL
				)
			);
		}

		if ( ! $created ) {
			return false;
		}

		return array(
			'name'  => $lock_name,
			'value' => $lock_value,
		);
	}

	/**
	 * Release a callback lock only when it is still owned by this request.
	 *
	 * @param array|false $lock Lock data returned by acquireCallbackLock().
	 * @return void
	 */
	protected function releaseCallbackLock( $lock ) {
		if ( ! is_array( $lock ) || empty( $lock['name'] ) || empty( $lock['value'] ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock['name'],
				$lock['value']
			)
		);
	}

	/**
	 * Check whether WooCommerce already contains durable payment evidence.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	protected function orderHasRecordedPayment( $order ) {
		return $order->is_paid() || (bool) $order->get_date_paid();
	}

	/**
	 * Determine whether a negative callback must not change the order.
	 *
	 * @param WC_Order $order                     Order object.
	 * @param string   $callback_hutko_order_id   Hutko order ID from callback.
	 * @param string   $active_hutko_order_id     Most recently created Hutko order ID.
	 * @param string   $successful_hutko_order_id Previously approved Hutko order ID.
	 * @return string Empty string when the callback may be applied, otherwise a reason code.
	 */
	protected function getNegativeCallbackIgnoreReason( $order, $callback_hutko_order_id, $active_hutko_order_id, $successful_hutko_order_id ) {
		if ( '' !== $active_hutko_order_id && ! hash_equals( $active_hutko_order_id, $callback_hutko_order_id ) ) {
			return 'stale_attempt';
		}

		if ( $this->orderHasRecordedPayment( $order ) ) {
			return 'payment_recorded';
		}

		if ( '' !== $successful_hutko_order_id ) {
			return 'approved_callback_recorded';
		}

		if ( $order->get_payment_method() && $this->id !== $order->get_payment_method() ) {
			return 'different_payment_method';
		}

		return '';
	}

	/**
	 * Record a negative callback that was deliberately ignored.
	 *
	 * @param WC_Order $order                   Order object.
	 * @param array    $request_body            Sanitized callback data.
	 * @param string   $reason                  Ignore reason code.
	 * @param string   $active_hutko_order_id   Most recently created Hutko order ID.
	 * @return void
	 */
	protected function recordIgnoredNegativeCallback( $order, $request_body, $reason, $active_hutko_order_id ) {
		$reason_labels = array(
			'stale_attempt'              => __( 'the callback belongs to an older payment attempt', 'oplata-woocommerce-payment-gateway' ),
			'payment_recorded'           => __( 'the order already contains payment evidence', 'oplata-woocommerce-payment-gateway' ),
			'approved_callback_recorded' => __( 'an approved Hutko callback was already recorded', 'oplata-woocommerce-payment-gateway' ),
			'different_payment_method'   => __( 'the order currently uses another payment method', 'oplata-woocommerce-payment-gateway' ),
		);
		$reason_label  = $reason_labels[ $reason ] ?? $reason;
		$callback_id   = (string) $request_body['order_id'];
		$active_id     = '' !== $active_hutko_order_id ? $active_hutko_order_id : __( 'not available', 'oplata-woocommerce-payment-gateway' );
		$payment_id    = isset( $request_body['payment_id'] ) ? (string) $request_body['payment_id'] : '';
		$order_note    = sprintf(
			/* translators: 1) callback status 2) callback order ID 3) payment ID 4) reason 5) active order ID */
			__( 'Hutko callback ignored: status %1$s, order ID %2$s, payment ID %3$s. Reason: %4$s. Active Hutko order ID: %5$s.', 'oplata-woocommerce-payment-gateway' ),
			$request_body['order_status'],
			$callback_id,
			$payment_id,
			$reason_label,
			$active_id
		);

		$order->add_order_note( $order_note );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log(
				'warning',
				$order_note,
				array(
					'source'         => 'hutko-payment-gateway',
					'order_id'       => $order->get_id(),
					'hutko_order_id' => $callback_id,
				)
			);
		}
	}

	/**
	 * Validate that an approved callback represents the payment attempt sent to Hutko.
	 *
	 * @param WC_Order $order                   Order object.
	 * @param array    $request_body            Sanitized callback data.
	 * @param string   $callback_hutko_order_id Full Hutko order ID from callback.
	 * @return void
	 * @throws Exception When approved callback data is invalid or does not match the attempt.
	 */
	protected function validateApprovedCallback( $order, $request_body, $callback_hutko_order_id ) {
		foreach ( array( 'payment_id', 'response_status', 'amount', 'currency' ) as $required_field ) {
			if ( ! isset( $request_body[ $required_field ] ) || ! is_scalar( $request_body[ $required_field ] ) || '' === (string) $request_body[ $required_field ] ) {
				throw new Exception( __( 'Required approved callback data is missing', 'oplata-woocommerce-payment-gateway' ) );
			}
		}

		if ( 'success' !== strtolower( (string) $request_body['response_status'] ) ) {
			throw new Exception( __( 'Hutko did not report a successful callback response', 'oplata-woocommerce-payment-gateway' ) );
		}

		if ( 'purchase' !== strtolower( (string) $request_body['tran_type'] ) ) {
			throw new Exception( __( 'Approved Hutko callback has an unexpected transaction type', 'oplata-woocommerce-payment-gateway' ) );
		}

		$callback_amount = (string) $request_body['amount'];
		if ( ! preg_match( '/^\d+$/', $callback_amount ) ) {
			throw new Exception( __( 'Approved Hutko callback amount is invalid', 'oplata-woocommerce-payment-gateway' ) );
		}

		$expected_attempt = $this->getPaymentAttempt( $order, $callback_hutko_order_id );
		if ( null === $expected_attempt ) {
			$expected_amount   = (int) round( (float) $order->get_total() * 100 );
			$expected_currency = strtoupper( (string) $order->get_currency() );
			$callback_currency = strtoupper( (string) $request_body['currency'] );

			if ( (int) $callback_amount !== $expected_amount || ! hash_equals( $expected_currency, $callback_currency ) ) {
				throw new Exception( __( 'Approved Hutko callback amount or currency does not match the legacy WooCommerce order', 'oplata-woocommerce-payment-gateway' ) );
			}

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					'Approved Hutko callback matched a legacy WooCommerce order without stored attempt metadata.',
					array(
						'source'         => 'hutko-payment-gateway',
						'order_id'       => $order->get_id(),
						'hutko_order_id' => $callback_hutko_order_id,
					)
				);
			}

			return;
		}

		if ( ! isset( $expected_attempt['amount'], $expected_attempt['currency'] ) ) {
			throw new Exception( __( 'Stored Hutko payment attempt data is incomplete', 'oplata-woocommerce-payment-gateway' ) );
		}

		$expected_amount   = (int) $expected_attempt['amount'];
		$expected_currency = strtoupper( (string) $expected_attempt['currency'] );
		$callback_currency = strtoupper( (string) $request_body['currency'] );

		if ( (int) $callback_amount !== $expected_amount || ! hash_equals( $expected_currency, $callback_currency ) ) {
			throw new Exception( __( 'Approved Hutko callback amount or currency does not match the payment attempt', 'oplata-woocommerce-payment-gateway' ) );
		}
	}

	/**
	 * Handle payment callback from hutko.
	 *
	 * @return void
	 */
	public function callbackHandler() {
		$order         = null;
		$request_body  = array();
		$callback_lock = false;

		try {
			$raw_input     = file_get_contents( 'php://input' );
			$decoded_input = is_string( $raw_input ) && '' !== $raw_input ? json_decode( $raw_input, true ) : null;
			$request_body  = is_array( $decoded_input ) ? $decoded_input : array();

			if ( empty( $request_body ) && ! empty( $_POST ) ) {
				$request_body = wp_unslash( $_POST );
			}

			if ( empty( $request_body ) || ! is_array( $request_body ) ) {
				throw new Exception( __( 'No valid callback data received', 'oplata-woocommerce-payment-gateway' ) );
			}

			// Validate the signature against the original values sent by hutko.
			WC_Oplata_API::validateRequest( $request_body );

			foreach ( array( 'order_id', 'order_status', 'tran_type' ) as $required_field ) {
				if ( ! isset( $request_body[ $required_field ] ) || ! is_scalar( $request_body[ $required_field ] ) ) {
					throw new Exception( __( 'Required callback data is missing', 'oplata-woocommerce-payment-gateway' ) );
				}
			}

			$fields_to_sanitize = array(
				'order_id',
				'order_status',
				'tran_type',
				'currency',
				'sender_email',
				'card_type',
				'payment_system',
				'response_status',
				'masked_card',
				'approval_code',
				'rrn',
				'eci',
				'response_code',
				'response_description',
				'payment_id',
				'card_bin',
				'amount',
				'actual_amount',
				'reversal_amount',
				'rectoken',
			);

			foreach ( $fields_to_sanitize as $field ) {
				if ( isset( $request_body[ $field ] ) && is_scalar( $request_body[ $field ] ) ) {
					$request_body[ $field ] = sanitize_text_field( (string) $request_body[ $field ] );
				}
			}

			// Ignore reverse callbacks.
			if ( ! empty( $request_body['reversal_amount'] ) || 'reverse' === $request_body['tran_type'] ) {
				status_header( 200 );
				exit;
			}

			if ( ! preg_match( '/^(\d+)' . preg_quote( self::ORDER_SEPARATOR, '/' ) . '.+$/', $request_body['order_id'], $order_id_matches ) ) {
				throw new Exception( __( 'Invalid WooCommerce order ID', 'oplata-woocommerce-payment-gateway' ) );
			}

			$order_id = absint( $order_id_matches[1] );

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new Exception( __( 'WooCommerce order was not found', 'oplata-woocommerce-payment-gateway' ) );
			}

			$callback_lock = $this->acquireCallbackLock( $order_id );
			if ( ! $callback_lock ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->log(
						'warning',
						'Hutko callback deferred because another callback is processing the same order.',
						array(
							'source'         => 'hutko-payment-gateway',
							'order_id'       => $order_id,
							'hutko_order_id' => $request_body['order_id'],
						)
					);
				}

				wp_send_json( array( 'error' => __( 'Another Hutko callback is currently processing this order', 'oplata-woocommerce-payment-gateway' ) ), 409 );
			}

			$callback_hutko_order_id = (string) $request_body['order_id'];
			if ( self::ORDER_APPROVED === $request_body['order_status'] ) {
				$this->validateApprovedCallback( $order, $request_body, $callback_hutko_order_id );
			}

			$this->clearCache( $request_body, $order_id );

			$active_hutko_order_id     = (string) $this->getOplataOrderID( $order );
			$successful_hutko_order_id = (string) $order->get_meta( self::META_NAME_HUTKO_SUCCESSFUL_ORDER_ID );
			$successful_payment_id     = (string) $order->get_meta( self::META_NAME_HUTKO_SUCCESSFUL_PAYMENT_ID );

			switch ( $request_body['order_status'] ) {
				case self::ORDER_APPROVED:
					if ( empty( $request_body['payment_id'] ) || ! preg_match( '/^\d+$/', (string) $request_body['payment_id'] ) ) {
						throw new Exception( __( 'hutko payment ID is missing', 'oplata-woocommerce-payment-gateway' ) );
					}

					$callback_payment_id = (string) $request_body['payment_id'];
					$stored_payment_id   = (string) $order->get_transaction_id();
					$same_payment        = ( '' !== $stored_payment_id && hash_equals( $stored_payment_id, $callback_payment_id ) ) ||
						( '' !== $successful_payment_id && hash_equals( $successful_payment_id, $callback_payment_id ) );

					if ( $this->orderHasRecordedPayment( $order ) || $same_payment || '' !== $successful_hutko_order_id ) {
						if ( $same_payment && '' === $successful_hutko_order_id ) {
							$order->update_meta_data( self::META_NAME_HUTKO_SUCCESSFUL_ORDER_ID, $callback_hutko_order_id );
							$order->update_meta_data( self::META_NAME_HUTKO_SUCCESSFUL_PAYMENT_ID, $callback_payment_id );
							$order->save();
						}

						if ( ! $same_payment ) {
							$order_note = sprintf(
								/* translators: 1) callback order ID 2) callback payment ID 3) stored payment ID */
								__( 'Additional approved Hutko callback ignored: order ID %1$s, payment ID %2$s. The WooCommerce order already has payment ID %3$s. Verify that the customer was not charged twice.', 'oplata-woocommerce-payment-gateway' ),
								$callback_hutko_order_id,
								$callback_payment_id,
								'' !== $stored_payment_id ? $stored_payment_id : $successful_payment_id
							);
							$order->add_order_note( $order_note );

							if ( function_exists( 'wc_get_logger' ) ) {
								wc_get_logger()->log(
									'warning',
									$order_note,
									array(
										'source'         => 'hutko-payment-gateway',
										'order_id'       => $order->get_id(),
										'hutko_order_id' => $callback_hutko_order_id,
									)
								);
							}
						}

						break;
					}

					if ( ! empty( $request_body['rectoken'] ) && $this->recurrent_payment ) {
						$order->update_meta_data( '_hutko_rectoken', $request_body['rectoken'] );
						$order->save();
					}

					do_action( 'wc_gateway_hutko_receive_valid_callback', $request_body, $order );

					$this->oplataPaymentComplete( $order, $callback_payment_id );

					$stored_payment_id = (string) $order->get_transaction_id();
					if ( ! $this->orderHasRecordedPayment( $order ) && ( '' === $stored_payment_id || ! hash_equals( $stored_payment_id, $callback_payment_id ) ) ) {
						throw new Exception( __( 'WooCommerce did not record the approved Hutko payment', 'oplata-woocommerce-payment-gateway' ) );
					}

					$order->update_meta_data( self::META_NAME_HUTKO_SUCCESSFUL_ORDER_ID, $callback_hutko_order_id );
					$order->update_meta_data( self::META_NAME_HUTKO_SUCCESSFUL_PAYMENT_ID, $callback_payment_id );
					$order->save();
					break;

				case self::ORDER_CREATED:
				case self::ORDER_PROCESSING:
					do_action( 'wc_gateway_hutko_receive_valid_callback', $request_body, $order );
					// Default WooCommerce pending status is used.
					break;

				case self::ORDER_DECLINED:
				case self::ORDER_EXPIRED:
					$ignore_reason = $this->getNegativeCallbackIgnoreReason( $order, $callback_hutko_order_id, $active_hutko_order_id, $successful_hutko_order_id );
					if ( $ignore_reason ) {
						$this->recordIgnoredNegativeCallback( $order, $request_body, $ignore_reason, $active_hutko_order_id );
						break;
					}

					if ( self::ORDER_DECLINED === $request_body['order_status'] ) {
						$new_order_status = 'default' !== $this->declined_order_status ? $this->declined_order_status : 'failed';
					} else {
						$new_order_status = 'default' !== $this->expired_order_status ? $this->expired_order_status : 'cancelled';
					}

					do_action( 'wc_gateway_hutko_receive_valid_callback', $request_body, $order );

					/* translators: 1) order status 2) payment ID */
					$order_note = sprintf( __( 'Transaction ERROR: order %1$s<br/>hutko ID: %2$s', 'oplata-woocommerce-payment-gateway' ), $request_body['order_status'], $request_body['payment_id'] ?? '' );
					$order->update_status( $new_order_status, $order_note );
					break;

				default:
					throw new Exception( __( 'Unhandled hutko order status', 'oplata-woocommerce-payment-gateway' ) );
			}
			$this->releaseCallbackLock( $callback_lock );
			$callback_lock = false;
		} catch ( Throwable $e ) {
			$this->releaseCallbackLock( $callback_lock );
			$callback_lock = false;

			if ( function_exists( 'wc_get_logger' ) ) {
				$log_context = array( 'source' => 'hutko-payment-gateway' );

				if ( isset( $request_body['order_id'] ) && is_scalar( $request_body['order_id'] ) ) {
					$log_context['hutko_order_id'] = sanitize_text_field( (string) $request_body['order_id'] );
				}

				if ( isset( $request_body['order_status'] ) && is_scalar( $request_body['order_status'] ) ) {
					$log_context['hutko_order_status'] = sanitize_text_field( (string) $request_body['order_status'] );
				}

				wc_get_logger()->error( 'Hutko callback error: ' . $e->getMessage(), $log_context );
			}

			wp_send_json( array( 'error' => 'Invalid callback request.' ), 400 );
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Complete payment process.
	 *
	 * @param WC_Order $order          Order object.
	 * @param string   $transaction_id Transaction ID from hutko.
	 * @return void
	 */
	public function oplataPaymentComplete( $order, $transaction_id ) {
		if ( ! $order->is_paid() ) {
			if ( ! $order->payment_complete( $transaction_id ) ) {
				throw new Exception( __( 'WooCommerce could not complete the approved Hutko payment', 'oplata-woocommerce-payment-gateway' ) );
			}

			/* translators: %1$s: transaction ID */
			$order_note = sprintf( __( 'hutko payment successful.<br/>hutko ID: %1$s<br/>', 'oplata-woocommerce-payment-gateway' ), $transaction_id );

			if ( 'default' !== $this->completed_order_status ) {
				WC()->cart->empty_cart();
				$order->update_status( $this->completed_order_status, $order_note );
			} else {
				$order->add_order_note( $order_note );
			}
		}
	}

	/**
	 * Register recurrent payment meta box on order screen.
	 *
	 * @return void
	 */
	public function addRecurrentPaymentMetaBox() {
		$screen = $this->hpos_in_use ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'hutko_recurrent_payment',
			__( 'hutko Manual Charge', 'oplata-woocommerce-payment-gateway' ),
			array( $this, 'renderRecurrentPaymentMetaBox' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render recurrent payment meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post object or order (HPOS).
	 * @return void
	 */
	public function renderRecurrentPaymentMetaBox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$rectoken = $order->get_meta( '_hutko_rectoken' );

		if ( empty( $rectoken ) ) {
			echo '<p>' . esc_html__( 'No recurrent token available for this order.', 'oplata-woocommerce-payment-gateway' ) . '</p>';
			return;
		}

		$order_id      = $order->get_id();
		$currency      = $order->get_currency();
		$nonce         = wp_create_nonce( 'hutko_recurrent_charge' );
		$order_total   = (float) $order->get_total();
		$charged_total = (float) $order->get_meta( '_hutko_recurrent_charged_total' );
		$remaining     = max( $order_total - $charged_total, 0 );
		?>
		<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
			<tr>
				<td><?php esc_html_e( 'Order total:', 'oplata-woocommerce-payment-gateway' ); ?></td>
				<td style="text-align:right;"><?php echo esc_html( number_format( $order_total, 2, '.', '' ) . ' ' . $currency ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Charged (recurrent):', 'oplata-woocommerce-payment-gateway' ); ?></td>
				<td style="text-align:right;" id="hutko_charged_total"><?php echo esc_html( number_format( $charged_total, 2, '.', '' ) . ' ' . $currency ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Remaining:', 'oplata-woocommerce-payment-gateway' ); ?></strong></td>
				<td style="text-align:right;" id="hutko_remaining"><strong><?php echo esc_html( number_format( $remaining, 2, '.', '' ) . ' ' . $currency ); ?></strong></td>
			</tr>
		</table>
		<p>
			<label for="hutko_recurrent_amount"><?php esc_html_e( 'Amount', 'oplata-woocommerce-payment-gateway' ); ?> (<?php echo esc_html( $currency ); ?>):</label>
			<input type="number" id="hutko_recurrent_amount" step="0.01" min="0.01" value="<?php echo esc_attr( number_format( $remaining > 0 ? $remaining : $order_total, 2, '.', '' ) ); ?>" style="width:100%;" />
		</p>
		<p>
			<button type="button" class="button button-primary" id="hutko_recurrent_charge_btn"><?php esc_html_e( 'Charge', 'oplata-woocommerce-payment-gateway' ); ?></button>
			<span id="hutko_recurrent_status" style="margin-left:8px;"></span>
		</p>
		<script>
		(function(){
			var btn = document.getElementById('hutko_recurrent_charge_btn');
			var status = document.getElementById('hutko_recurrent_status');
			var orderTotal = <?php echo esc_js( $order_total ); ?>;
			var chargedTotal = <?php echo esc_js( $charged_total ); ?>;
			var currency = '<?php echo esc_js( $currency ); ?>';

			btn.addEventListener('click', function(){
				var amount = parseFloat(document.getElementById('hutko_recurrent_amount').value);
				if ( ! amount || amount <= 0 ) {
					status.textContent = '<?php echo esc_js( __( 'Please enter a valid amount.', 'oplata-woocommerce-payment-gateway' ) ); ?>';
					status.style.color = 'red';
					return;
				}

				var newTotal = chargedTotal + amount;
				if ( newTotal > orderTotal ) {
					var msg = '<?php echo esc_js( __( 'This will charge %1$s %2$s. Total charged will be %3$s %2$s, which exceeds the order total of %4$s %2$s. Continue?', 'oplata-woocommerce-payment-gateway' ) ); ?>';
					msg = msg.replace('%1$s', amount.toFixed(2)).replace(/%2\$s/g, currency).replace('%3$s', newTotal.toFixed(2)).replace('%4$s', orderTotal.toFixed(2));
					if ( ! confirm(msg) ) {
						return;
					}
				}

				btn.disabled = true;
				status.textContent = '<?php echo esc_js( __( 'Processing...', 'oplata-woocommerce-payment-gateway' ) ); ?>';
				status.style.color = '';

				var data = new FormData();
				data.append('action', 'hutko_recurrent_charge');
				data.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');
				data.append('order_id', '<?php echo esc_js( $order_id ); ?>');
				data.append('amount', amount);

				fetch(ajaxurl, { method: 'POST', body: data })
					.then(function(r){ return r.json(); })
					.then(function(resp){
						if ( resp.success ) {
							status.textContent = resp.data.message || 'OK';
							status.style.color = 'green';
							chargedTotal = parseFloat(resp.data.charged_total) || (chargedTotal + amount);
							var remaining = Math.max(orderTotal - chargedTotal, 0);
							document.getElementById('hutko_charged_total').textContent = chargedTotal.toFixed(2) + ' ' + currency;
							document.getElementById('hutko_remaining').innerHTML = '<strong>' + remaining.toFixed(2) + ' ' + currency + '</strong>';
							document.getElementById('hutko_recurrent_amount').value = remaining > 0 ? remaining.toFixed(2) : orderTotal.toFixed(2);
						} else {
							status.textContent = resp.data.message || 'Error';
							status.style.color = 'red';
						}
						btn.disabled = false;
					})
					.catch(function(){
						status.textContent = 'Request failed';
						status.style.color = 'red';
						btn.disabled = false;
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX handler for recurrent charge.
	 *
	 * @return void
	 */
	public function ajaxRecurrentCharge() {
		check_ajax_referer( 'hutko_recurrent_charge' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'oplata-woocommerce-payment-gateway' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$amount   = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;

		if ( ! $order_id || $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order or amount.', 'oplata-woocommerce-payment-gateway' ) ) );
		}

		$order    = wc_get_order( $order_id );
		$rectoken = $order ? $order->get_meta( '_hutko_rectoken' ) : '';

		if ( ! $order || empty( $rectoken ) ) {
			wp_send_json_error( array( 'message' => __( 'Order or recurrent token not found.', 'oplata-woocommerce-payment-gateway' ) ) );
		}

		try {
			$result = WC_Oplata_API::recurring(
				array(
					'order_id'   => $this->createOplataOrderID( $order ),
					'amount'     => (int) round( $amount * 100 ),
					'currency'   => $order->get_currency(),
					'rectoken'   => $rectoken,
					'order_desc' => sprintf(
						/* translators: %s: order number */
						__( 'Recurrent payment for order #%s', 'oplata-woocommerce-payment-gateway' ),
						$order->get_order_number()
					),
				)
			);

			if ( 'approved' === $result->order_status ) {
				$charged_total = (float) $order->get_meta( '_hutko_recurrent_charged_total' );
				$charged_total += $amount;
				$order->update_meta_data( '_hutko_recurrent_charged_total', $charged_total );
				$order->save();

				/* translators: 1) amount 2) currency 3) payment ID */
				$note = sprintf(
					__( 'Charge successful: %1$s %2$s. Hutko ID: %3$s', 'oplata-woocommerce-payment-gateway' ),
					$amount,
					$order->get_currency(),
					$result->payment_id
				);
				$order->add_order_note( $note );
				wp_send_json_success( array( 'message' => $note, 'charged_total' => $charged_total ) );
			} else {
				/* translators: %s: order status */
				$note = sprintf(
					__( 'Recurrent charge failed. Status: %s', 'oplata-woocommerce-payment-gateway' ),
					$result->order_status
				);
				$order->add_order_note( $note );
				wp_send_json_error( array( 'message' => $note ) );
			}
		} catch ( Exception $e ) {
			/* translators: %s: error message */
			$note = sprintf(
				__( 'Recurrent charge error: %s', 'oplata-woocommerce-payment-gateway' ),
				$e->getMessage()
			);
			$order->add_order_note( $note );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
