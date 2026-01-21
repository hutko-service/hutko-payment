<?php
/**
 * Hosted payment form integration trait.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for hosted payment form functionality.
 *
 * @since 3.0.0
 */
trait Oplata_Hosted {

	/**
	 * Flag indicating hosted integration is available.
	 *
	 * @var bool
	 */
	public $hosted = true;
}
