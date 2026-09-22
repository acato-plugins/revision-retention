/**
 * Settings screen of the Revision Retention plugin.
 *
 * Two small conveniences, both cosmetic: the scheduling fields are dimmed
 * while the scheduled sweep is switched off, and the post types that hold no
 * revisions start out folded away. Everything is still submitted and still
 * saved either way, and the server decides what it means.
 */
( function () {
	'use strict';

	function syncSchedule() {
		var toggle = document.getElementById( 'rvrt-cron-enabled' );

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

		function apply() {
			var enabled = isEnabled();

			[ 'rvrt-cron-interval', 'rvrt-batch-size' ].forEach( function ( id ) {
				var field = document.getElementById( id );
				var row = field && field.closest( 'tr' );

				if ( row ) {
					row.setAttribute( 'aria-disabled', enabled ? 'false' : 'true' );
				}
			} );
		}

		toggle.addEventListener( 'change', apply );
		apply();
	}

	function syncQuietRows() {
		var button = document.querySelector( '.rvrt-toggle' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var shown = '1' === button.getAttribute( 'data-shown' );

			document.querySelectorAll( '.rvrt-quiet' ).forEach( function ( row ) {
				row.hidden = shown;
			} );

			button.setAttribute( 'data-shown', shown ? '0' : '1' );
			button.textContent = shown
				? button.getAttribute( 'data-show' )
				: button.getAttribute( 'data-hide' );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		syncSchedule();
		syncQuietRows();
	} );
}() );
