/**
 * Hutko embedded payment form initialization script.
 *
 * @package Hutko_Payment_Gateway
 * @since 3.0.0
 */

( function() {
	'use strict';

	/**
	 * Initialize the hutko payment widget.
	 */
	var initOplataWidget = function() {
		hutko( '#oplata-checkout-container', oplataPaymentArguments );
	};

	/**
	 * Load external script dynamically.
	 *
	 * @param {string}   src      Script URL.
	 * @param {Function} callback Callback function after load.
	 */
	var loadScript = function( src, callback ) {
		var script = document.createElement( 'script' );
		script.src = src;
		script.id = 'oplata_script';
		script.onload = callback;
		document.head.appendChild( script );
	};

	/**
	 * Setup payment widget with proper options.
	 */
	var setupPaymentWidget = function() {
		var options = {
			options: {
				methods: [ 'card', 'wallets' ],
				methods_disabled: [],
				card_icons: oplataPaymentArguments.options.card_icons,
				fields: false,
				active_tab: 'card',
				title: oplataPaymentArguments.options.title,
				link: oplataPaymentArguments.options.url,
				full_screen: oplataPaymentArguments.options.full_screen,
				button: true,
				email: true
			},
			params: {
				token: oplataPaymentArguments.params.token
			}
		};

		var orderReviewElement = document.querySelector( '#order_review' );
		if ( orderReviewElement ) {
			orderReviewElement.style.display = 'none';

			if ( ! document.getElementById( 'oplata-checkout-container' ) ) {
				var container = document.createElement( 'div' );
				container.id = 'oplata-checkout-container';
				orderReviewElement.parentNode.insertBefore( container, orderReviewElement );
			}
		}

		hutko( '#oplata-checkout-container', options );
	};

	// Check if payment arguments are defined.
	if ( typeof oplataPaymentArguments === 'undefined' ) {
		return;
	}

	// Initialize widget based on script availability.
	if ( null === document.getElementById( 'oplata_script' ) ) {
		loadScript( 'https://pay.hutko.org/latest/checkout-vue/checkout.js', setupPaymentWidget );
	} else {
		initOplataWidget();
	}
} )();
