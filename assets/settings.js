/**
 * Settings screen of the Revision Retention plugin.
 *
 * Dims the scheduling fields while the scheduled sweep is switched off, so the
 * screen says what it is going to do. Purely cosmetic: the values are still
 * submitted and still saved, and the server decides what they mean.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var toggle = document.getElementById( 'rvrt-cron-enabled' );
		var fields = [
			document.getElementById( 'rvrt-cron-interval' ),
			document.getElementById( 'rvrt-batch-size' ),
		];

		if ( ! toggle ) {
			return;
		}

		function isEnabled() {
			// A checkbox on a site that owns its settings, a three way select
			// when the value can be inherited from the network instead.
			if ( 'checkbox' === toggle.type ) {
				return toggle.checked;
			}

			return '0' !== toggle.value;
		}

		function sync() {
			var enabled = isEnabled();

			fields.forEach( function ( field ) {
				if ( ! field ) {
					return;
				}

				var row = field.closest( 'tr' );

				if ( row ) {
					row.setAttribute( 'aria-disabled', enabled ? 'false' : 'true' );
				}
			} );
		}

		toggle.addEventListener( 'change', sync );
		sync();
	} );
}() );
