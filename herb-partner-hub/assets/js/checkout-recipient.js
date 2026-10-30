/* Herb Partner Hub — checkout "Ship this order to".
 * Fills WooCommerce's shipping_* fields from the chosen saved customer and shows them
 * read-only, so the dropshipper can check them before paying. Billing is the
 * dropshipper's own (they pay, and the card checks compare it) and is never touched.
 * The server copies the same address again on submit, so this script only keeps the
 * screen and the shipping price in step. update_checkout fires when the recipient
 * changes, never on page load. */
( function ( $ ) {
	'use strict';
	if ( ! $ ) {
		return;
	}

	// CartFlows saves the checkout fields in localStorage and puts them back in its own
	// DOM-ready callback. For a dropshipper the box owns the shipping address, so an old
	// shipping_* value (often a different customer's) must not come back over the one the
	// server rendered.
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
					return 0 !== n.indexOf( 'shipping_' );
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
		var mode = 'self';
		var $form = $box.closest( 'form' );
		var TEXT = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'phone' ];

		function $toggle() {
			return $( '#ship-to-different-address-checkbox' );
		}

		function $fields() {
			return $( 'div.shipping_address' );
		}

		// CartFlows' modern layout floats a field's label once it has a value, by adding
		// wcf-anim-label on its own input events. Values set here fire none, so set the class
		// too, or a filled field shows no label. A value also clears any "required" error the
		// field picked up while empty: "New customer" focuses the empty first name, so picking
		// a saved customer next leaves WooCommerce's and CartFlows' error on a filled field.
		function set( name, value, prefix ) {
			var $f = $( '#' + ( prefix || 'shipping' ) + '_' + name );
			if ( ! $f.length ) {
				return;
			}
			var filled = '' !== String( value );
			var $row = $f.closest( '.form-row' );
			$f.val( value );
			$row.toggleClass( 'wcf-anim-label', filled );
			if ( filled ) {
				$row.removeClass( 'woocommerce-invalid woocommerce-invalid-required-field' )
					.find( '.wcf-field-required-error, .checkout-inline-error-message' ).remove();
				$f.removeClass( 'field-required' ).removeAttr( 'aria-invalid' );
			}
		}

		function refresh() {
			$( document.body ).trigger( 'update_checkout' );
		}

		// Country first: WooCommerce rebuilds the state field when the country changes.
		function fill( addr, done ) {
			TEXT.forEach( function ( k ) {
				set( k, addr[ k ] || '' );
			} );
			var $country = $( '#shipping_country' );

			function finish() {
				set( 'state', addr.state || '' );
				$( '#shipping_state' ).trigger( 'change.select2' );
				done();
			}

			if ( $country.length && addr.country && $country.val() !== addr.country ) {
				$( document.body ).one( 'country_to_state_changed', finish );
				$country.val( addr.country ).trigger( 'change' );
			} else {
				finish();
			}
		}

		function show( value ) {
			$box.find( '.hph-ro' ).each( function () {
				var $l = $( this );
				$l.toggleClass( 'is-on', $l.find( 'input' ).val() === value );
			} );
			$box.find( '[data-hph-save]' ).prop( 'hidden', 'new' !== value );
			mode = ( '' === value || 'self' === value ) ? 'self' : ( 'new' === value ? 'new' : 'customer' );
			// A saved customer's address is shown to check, not to edit: the server takes it
			// from My Customers on submit whatever the form says.
			TEXT.forEach( function ( k ) {
				$( '#shipping_' + k ).prop( 'readonly', 'customer' === mode );
			} );
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
				done();
			} else if ( 'new' === value ) {
				$toggle().prop( 'checked', true );
				TEXT.forEach( function ( k ) {
					set( k, '' );
				} );
				set( 'state', '' );
				$fields().show();
				done();
				$( '#shipping_first_name' ).trigger( 'focus' );
			} else if ( book[ value ] ) {
				$toggle().prop( 'checked', true );
				$fields().show();
				fill( book[ value ], done );
			}
		}

		$form.addClass( 'hph-ship-managed' );

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
			$fields().show();
			if ( 'new' !== initial ) {
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
