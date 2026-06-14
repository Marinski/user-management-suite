/**
 * reCAPTCHA helper for User Management Suite.
 *
 * For v3, fetches a token on form submit and injects it into the hidden field.
 * For v2 / v2-invisible, Google's api.js renders the widget automatically.
 */
( function () {
	'use strict';

	var cfg = window.umsRecaptcha || {};
	if ( 'v3' !== cfg.version || ! cfg.siteKey ) {
		return;
	}

	function hook( form ) {
		if ( form.dataset.umsRecaptchaBound ) {
			return;
		}
		form.dataset.umsRecaptchaBound = '1';

		form.addEventListener( 'submit', function ( e ) {
			var field = form.querySelector( '.ums-recaptcha-token' );
			if ( ! field || field.value ) {
				return;
			}

			e.preventDefault();

			window.grecaptcha.ready( function () {
				window.grecaptcha.execute( cfg.siteKey, { action: 'submit' } ).then( function ( token ) {
					field.value = token;
					form.submit();
				} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var fields = document.querySelectorAll( '.ums-recaptcha-token' );
		Array.prototype.forEach.call( fields, function ( field ) {
			if ( field.form ) {
				hook( field.form );
			}
		} );
	} );
} )();
