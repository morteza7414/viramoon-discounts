/**
 * Viramoon Product Discounts – checkout recalculation helper.
 *
 * WooCommerce recalculates the order review when the payment method changes, but
 * customised checkouts (and the "switch back to a gateway without a commission"
 * case) often miss it. This script makes sure the totals are recalculated
 * whenever the selected gateway changes, without firing a duplicate request when
 * WooCommerce handled it itself.
 */
( function () {
	'use strict';

	if ( typeof window.viramoonPDCheckout === 'undefined' ) {
		return;
	}

	var config = window.viramoonPDCheckout;
	var fees = config.fees || {};
	var gatewayIds = Object.keys( fees );

	if ( ! gatewayIds.length ) {
		return;
	}

	var anyFee = gatewayIds.some( function ( id ) {
		return parseFloat( fees[ id ] ) > 0;
	} );

	var lastGateway = null;
	var timer = null;
	var busy = false;
	var busyTimer = null;

	function currentGateway() {
		var input = document.querySelector( 'input[name="payment_method"]:checked' );
		if ( input ) {
			return input.value;
		}

		var select = document.querySelector( 'select[name="payment_method"]' );
		return select ? select.value : '';
	}

	function gatewayHasFee( gatewayId ) {
		return !! gatewayId && parseFloat( fees[ gatewayId ] || 0 ) > 0;
	}

	function triggerRecalc() {
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	function run() {
		// WooCommerce is already recalculating with the freshly selected gateway,
		// so there is nothing to do — avoid a duplicate request.
		if ( busy ) {
			return;
		}

		triggerRecalc();
	}

	function schedule( delay ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( run, delay || 250 );
	}

	function markBusy() {
		busy = true;

		// Safety net: never stay "busy" forever if updated_checkout is lost.
		window.clearTimeout( busyTimer );
		busyTimer = window.setTimeout( function () {
			busy = false;
		}, 10000 );
	}

	function markIdle() {
		window.setTimeout( function () {
			busy = false;
			window.clearTimeout( busyTimer );
		}, 200 );
	}

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'update_checkout', markBusy );
		window.jQuery( document.body ).on( 'updated_checkout', markIdle );
		window.jQuery( document.body ).on( 'checkout_error', function () {
			busy = false;
			window.clearTimeout( busyTimer );
		} );
	}

	function onGatewayChange( gatewayId ) {
		var previous = lastGateway;
		lastGateway = gatewayId;

		if ( gatewayId === previous ) {
			return;
		}

		// Whenever this plugin has a commission configured for any gateway, a
		// switch must be recalculated — including switching to a gateway with 0%
		// (the commission has to be dropped) and away from one with a commission.
		if ( ! anyFee && ! gatewayHasFee( gatewayId ) && ! gatewayHasFee( previous ) ) {
			return;
		}

		schedule();
	}

	// Capture phase so the handler also runs when a theme stops propagation.
	document.addEventListener(
		'change',
		function ( event ) {
			var target = event.target;
			if ( ! target || target.name !== 'payment_method' ) {
				return;
			}

			onGatewayChange( target.value );
		},
		true
	);

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'payment_method_selected', function () {
			onGatewayChange( currentGateway() );
		} );
	}

	function init() {
		lastGateway = currentGateway();

		// A stored session gateway may not match the radio that is pre-selected on
		// the first paint, so refresh once on load whenever a commission exists.
		if ( anyFee ) {
			schedule( 400 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
