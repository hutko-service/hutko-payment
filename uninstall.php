<?php
/**
 * Hutko Payment Gateway Uninstall.
 *
 * Removes all plugin data when uninstalled.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'hutko_woocommerce_version' );
delete_option( 'woocommerce_oplata_settings' );
delete_option( 'oplata_unique' );
