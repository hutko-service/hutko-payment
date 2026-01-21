/**
 * WooCommerce Blocks payment method registration for hutko.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.5.0
 */

( function() {
	'use strict';

	var settings = window.wc.wcSettings.getSetting( 'hutko_data', {} );
	var label = window.wp.htmlEntities.decodeEntities( settings.title ) || window.wp.i18n.__( 'hutko Gateway', 'hutko' );

	var Content = function() {
		return window.wp.htmlEntities.decodeEntities( settings.description || '' );
	};

	var HutkoPaymentBlockGateway = {
		name: 'hutko',
		label: label,
		content: Object( window.wp.element.createElement )( Content, null ),
		edit: Object( window.wp.element.createElement )( Content, null ),
		canMakePayment: function() {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports
		}
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( HutkoPaymentBlockGateway );
} )();
