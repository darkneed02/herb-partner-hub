/* Herb Partner Hub — checkout "Ship this order to".
 * Fills WooCommerce's shipping_* and billing_* fields from the chosen saved customer (a
 * new customer's billing follows what is typed in the shipping form), and puts the
 * dropshipper's own billing back for "Ship to myself". billing_email is never touched.
 * The server copies the same addresses again on submit, so this script only keeps the
 * screen and the shipping price in step. update_checkout fires when the recipient
 * changes, never on page load. */
( function ( $ ) {
	'use strict';
	if ( ! $ ) {
		return;
	}

	// CartFlows saves the checkout fields in localStorage and puts them back in its own
	// DOM-ready callback. For a dropshipper the box owns the shipping and billing address,
	// so an old shipping_* or billing_* value (often a different customer's, sometimes from
	// another login on this browser) must not come back over the one the server rendered.
	// This runs while the footer scripts load, before any DOM-ready callback.
	( function () {
		if ( ! document.getElementById( 'hph-recipient' ) ) {
			return;
		}
		try {
			var key = 'cartflows_checkout_form';
			var saved = JSON.parse( window.localStorage.getItem( key ) || 'null' );
			if ( Array.isArray( saved ) ) {
				window.localStorage.setItem( key, JSON.stringify( saved.filter( function ( f ) {
					var n = f && typeof f.name === 'string' ? f.name : '';
					return ! ( 0 === n.indexOf( 'shipping_' ) || ( 0 === n.indexOf( 'billing_' ) && 'billing_email' !== n ) );
				} ) ) );
			}
		} catch ( e ) {}
	}() );

	$( function () {
		var $box = $( '#hph-recipient' );
		if ( ! $box.length ) {
			return;
		}
		var book = ( window.hphRecipients && window.hphRecipients.book ) || {};
		var own = ( window.hphRecipients && window.hphRecipients.self ) || {};
		var mode = 'self';
		var $form = $box.closest( 'form' );
		var TEXT = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'phone' ];

		function $toggle() {
			return $( '#ship-to-different-address-checkbox' );
		}

		function $fields() {
			return $( 'div.shipping_address' );
		}

		function set( name, value, prefix ) {
			var $f = $( '#' + ( prefix || 'shipping' ) + '_' + name );
			if ( $f.length ) {
				$f.val( value );
			}
		}

		function refresh() {
			$( document.body ).trigger( 'update_checkout' );
		}

		// Country first: WooCommerce rebuilds the state field when the country changes.
		function fillGroup( prefix, addr, done ) {
			TEXT.forEach( function ( k ) {
				set( k, addr[ k ] || '', prefix );
			} );
			var $country = $( '#' + prefix + '_country' );

			function finish() {
				set( 'state', addr.state || '', prefix );
				$( '#' + prefix + '_state' ).trigger( 'change.select2' );
				done();
			}

			if ( $country.length && addr.country && $country.val() !== addr.country ) {
				$( document.body ).one( 'country_to_state_changed', finish );
				$country.val( addr.country ).trigger( 'change' );
			} else {
				finish();
			}
		}

		// The customer's address goes into both the shipping and the billing fields.
		function fill( addr, done ) {
			fillGroup( 'shipping', addr, function () {
				fillGroup( 'billing', addr, done );
			} );
		}

		function readShipping() {
			var addr = {};
			TEXT.concat( [ 'country', 'state' ] ).forEach( function ( k ) {
				addr[ k ] = $( '#shipping_' + k ).val() || '';
			} );
			return addr;
		}

		// Billing follows the recipient, so it is read-only while one is chosen: the server
		// would replace any edit anyway. Change a saved customer in My Customers.
		function lockBilling( on ) {
			TEXT.forEach( function ( k ) {
				$( '#billing_' + k ).prop( 'readonly', on );
			} );
			$box.find( '[data-hph-billing-note]' ).prop( 'hidden', ! on );
		}

		function show( value ) {
			$box.find( '.hph-ro' ).each( function () {
				var $l = $( this );
				$l.toggleClass( 'is-on', $l.find( 'input' ).val() === value );
			} );
			$box.find( '[data-hph-save]' ).prop( 'hidden', 'new' !== value );
			mode = ( '' === value || 'self' === value ) ? 'self' : ( 'new' === value ? 'new' : 'customer' );
			lockBilling( 'self' !== mode );
			$form.removeClass( 'hph-ship--self hph-ship--new hph-ship--customer' ).addClass( 'hph-ship--' + mode );
		}

		// The toggle is set without a change event: WooCommerce would refresh on it, and
		// the refresh is ours to trigger once the fields are filled.
		function apply( value, withRefresh ) {
			var done = withRefresh ? refresh : function () {};
			show( value );

			if ( 'self' === value ) {
				$toggle().prop( 'checked', false );
				$fields().hide();
				fillGroup( 'billing', own, done );
			} else if ( 'new' === value ) {
				$toggle().prop( 'checked', true );
				TEXT.forEach( function ( k ) {
					set( k, '' );
					set( k, '', 'billing' );
				} );
				set( 'state', '' );
				$fields().show();
				done();
				$( '#shipping_first_name' ).trigger( 'focus' );
			} else if ( book[ value ] ) {
				$toggle().prop( 'checked', true );
				$fields().hide();
				fill( book[ value ], done );
			}
		}

		$form.addClass( 'hph-ship-managed' );

		// New customer: what is typed into the delivery form is also the billing address.
		$form.on( 'input change', '.shipping_address :input', function () {
			if ( 'new' !== mode ) {
				return;
			}
			var addr = readShipping();
			if ( 'shipping_country' === this.id ) {
				fillGroup( 'billing', addr, function () {} );
			} else {
				TEXT.forEach( function ( k ) {
					set( k, addr[ k ], 'billing' );
				} );
				if ( $( '#billing_country' ).val() === addr.country ) {
					set( 'state', addr.state, 'billing' );
				}
			}
		} );

		$box.on( 'change', 'input[name="hph_ship_to"]', function () {
			apply( this.value, true );
		} );

		// Page load: match the form to the preselected choice. No refresh for "self" (its
		// address is the billing address), for nothing chosen, or after Reorder, where the
		// server already rendered the recipient's address and shipping price.
		var initial = $box.find( 'input[name="hph_ship_to"]:checked' ).val() || '';
		if ( '' === initial ) {
			show( '' );
			$toggle().prop( 'checked', false );
			$fields().hide();
		} else if ( 'self' === initial ) {
			apply( 'self', false );
		} else if ( $box.is( '[data-hph-prefilled]' ) ) {
			show( initial );
			$toggle().prop( 'checked', true );
			if ( 'new' === initial ) {
				$fields().show();
			} else {
				$fields().hide();
				// Fill again in case CartFlows restored older values from localStorage; this
				// changes nothing (and so refreshes nothing) when the fields already match.
				if ( book[ initial ] ) {
					fill( book[ initial ], function () {} );
				}
			}
		} else {
			apply( initial, true );
		}
	} );
}( window.jQuery ) );
