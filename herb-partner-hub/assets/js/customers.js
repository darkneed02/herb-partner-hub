/* Herb Partner Hub — My Customers: the state field follows the country (list or free text). */
( function () {
	'use strict';

	var cfg = window.hphCustomers || {};
	var states = cfg.states || {};
	var country = document.querySelector( '[data-hph-country]' );
	var wrap = document.querySelector( '[data-hph-state]' );
	if ( ! country || ! wrap ) {
		return;
	}

	country.addEventListener( 'change', function () {
		var old = wrap.querySelector( '#hph-c-state' );
		var list = states[ country.value ];
		var field;

		if ( list ) {
			field = document.createElement( 'select' );
			field.appendChild( new Option( cfg.choose || '', '' ) );
			Object.keys( list ).forEach( function ( code ) {
				field.appendChild( new Option( list[ code ], code ) );
			} );
		} else {
			field = document.createElement( 'input' );
			field.type = 'text';
			field.autocomplete = 'off';
		}
		field.id = 'hph-c-state';
		field.name = 'hph_customer[state]';
		old.parentNode.replaceChild( field, old );
	} );
}() );
