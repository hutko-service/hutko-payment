<?php
/**
 * Hutko API helper class.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides static methods for hutko API communication.
 *
 * @since 3.0.0
 */
class WC_Oplata_API {

	const TEST_MERCHANT_ID         = 1700002;
	const TEST_MERCHANT_SECRET_KEY = 'test';

	/**
	 * API endpoint URL.
	 *
	 * @var string
	 */
	private static $ApiUrl = 'https://pay.hutko.org/api/';

	/**
	 * Merchant ID.
	 *
	 * @var string
	 */
	private static $merchantID;

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	private static $secretKey;

	/**
	 * Get merchant ID.
	 *
	 * @return string
	 */
	public static function getMerchantID() {
		return self::$merchantID;
	}

	/**
	 * Set merchant ID.
	 *
	 * @param string $merchantID Merchant ID.
	 * @return void
	 */
	public static function setMerchantID( $merchantID ) {
		self::$merchantID = $merchantID;
	}

	/**
	 * Set secret key.
	 *
	 * @param string $secretKey Secret key.
	 * @return void
	 */
	public static function setSecretKey( $secretKey ) {
		self::$secretKey = $secretKey;
	}

	/**
	 * Get secret key.
	 *
	 * @return string
	 */
	public static function getSecretKey() {
		return self::$secretKey;
	}

	/**
	 * Get checkout URL from API.
	 *
	 * @param array $request_data Request data.
	 * @return string Checkout URL.
	 * @throws Exception When API call fails.
	 */
	public static function getCheckoutUrl( $request_data ) {
		$response = self::sendToAPI( 'checkout/url', $request_data );
		return $response->checkout_url;
	}

	/**
	 * Get checkout token from API.
	 *
	 * @param array $request_data Request data.
	 * @return string Checkout token.
	 * @throws Exception When API call fails.
	 */
	public static function getCheckoutToken( $request_data ) {
		$response = self::sendToAPI( 'checkout/token', $request_data );
		return $response->token;
	}

	/**
	 * Reverse (refund) a transaction.
	 *
	 * @param array $request_data Request data.
	 * @return object API response.
	 * @throws Exception When API call fails.
	 */
	public static function reverse( $request_data ) {
		return self::sendToAPI( 'reverse/order_id', $request_data );
	}

	/**
	 * Capture a preauthorized transaction.
	 *
	 * @param array $request_data Request data.
	 * @return object API response.
	 * @throws Exception When API call fails.
	 */
	public static function capture( $request_data ) {
		return self::sendToAPI( 'capture/order_id', $request_data );
	}

	/**
	 * Process recurring payment.
	 *
	 * @param array $request_data Request data.
	 * @return object API response.
	 * @throws Exception When API call fails.
	 */
	public static function recurring( $request_data ) {
		return self::sendToAPI( 'recurring', $request_data );
	}

	/**
	 * Send request to hutko API.
	 *
	 * @param string $endpoint     API endpoint.
	 * @param array  $request_data Request data.
	 * @return object API response.
	 * @throws Exception When API call fails.
	 */
	public static function sendToAPI( $endpoint, $request_data ) {
		$request_data['merchant_id'] = self::getMerchantID();
		$request_data['signature']   = self::getSignature( $request_data, self::getSecretKey() );

		$response = wp_safe_remote_post(
			self::$ApiUrl . $endpoint,
			array(
				'headers' => array( 'Content-type' => 'application/json;charset=UTF-8' ),
				'body'    => wp_json_encode( array( 'request' => $request_data ) ),
				'timeout' => 70,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			/* translators: %s: HTTP response code */
			throw new Exception( sprintf( __( 'hutko API Return code is %s. Please try again later.', 'oplata-woocommerce-payment-gateway' ), $response_code ) );
		}

		$result = json_decode( $response['body'] );

		if ( empty( $result->response ) && empty( $result->response->response_status ) ) {
			throw new Exception( __( 'Unknown hutko API answer.', 'oplata-woocommerce-payment-gateway' ) );
		}

		if ( 'success' !== $result->response->response_status ) {
			throw new Exception( $result->response->error_message );
		}

		return $result->response;
	}

	/**
	 * Generate signature for API request.
	 *
	 * @param array  $data     Request data.
	 * @param string $password Secret key.
	 * @param bool   $encoded  Whether to return encoded signature.
	 * @return string Signature.
	 */
	public static function getSignature( $data, $password, $encoded = true ) {
		$data = array_filter(
			$data,
			function ( $var ) {
				return '' !== $var && null !== $var;
			}
		);
		ksort( $data );

		$str = $password;
		foreach ( $data as $k => $v ) {
			$str .= '|' . $v;
		}

		return $encoded ? sha1( $str ) : $str;
	}

	/**
	 * Validate callback request from hutko.
	 *
	 * @param array $request_body Request body.
	 * @return void
	 * @throws Exception When validation fails.
	 */
	public static function validateRequest( $request_body ) {
    if ( empty( $request_body ) ) {
        throw new Exception( __( 'Empty request body.', 'oplata-woocommerce-payment-gateway' ) );
    }

    // Конвертуємо merchant_id в integer для порівняння
    $received_merchant_id = isset($request_body['merchant_id']) ? (int) $request_body['merchant_id'] : null;

    if ( self::$merchantID !== $received_merchant_id ) {
        throw new Exception( 
            sprintf(
                __( 'Merchant data is incorrect. Expected: %1$s, Received: %2$s', 'oplata-woocommerce-payment-gateway' ),
                self::$merchantID,
                $request_body['merchant_id'] ?? 'NULL'
            )
        );
    }

    $request_signature = $request_body['signature'];
    unset( $request_body['response_signature_string'] );
    unset( $request_body['signature'] );

    if ( $request_signature !== self::getSignature( $request_body, self::$secretKey ) ) {
        throw new Exception( __( 'Signature is not valid', 'oplata-woocommerce-payment-gateway' ) );
    }
  }
}
