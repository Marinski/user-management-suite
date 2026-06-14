/**
 * Hide WordPress's native single-role dropdown so the multi-role checklist
 * provided by User Management Suite is the single source of truth.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var select = document.querySelector( 'select#role, select[name="role"]' );
		if ( ! select ) {
			return;
		}

		// Remove the whole table row (label + control) when possible.
		var row = select.closest( 'tr' );
		if ( row ) {
			row.style.display = 'none';
		} else {
			select.style.display = 'none';
		}
	} );
} )();
