/**
 * Dashboard widget of the Revision Retention plugin.
 *
 * Makes the Site and Network tabs a network administrator gets in the widget
 * switch between the two views.
 */
( () => {
	'use strict';

	const syncWidgetTabs = ( widget ) => {
		const tabs = [ ...widget.querySelectorAll( '.rvrt-widget-tab' ) ];

		if ( ! tabs.length ) {
			return;
		}

		const select = ( tab, focus ) => {
			for ( const other of tabs ) {
				const active = other === tab;

				other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				other.tabIndex = active ? 0 : -1;
				document.getElementById( other.getAttribute( 'aria-controls' ) ).hidden = ! active;
			}

			if ( focus ) {
				tab.focus();
			}
		};

		tabs.forEach( ( tab, index ) => {
			tab.addEventListener( 'click', () => select( tab, false ) );

			tab.addEventListener( 'keydown', ( event ) => {
				const step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ] ?? 0;

				if ( ! step ) {
					return;
				}

				event.preventDefault();
				select( tabs[ ( index + step + tabs.length ) % tabs.length ], true );
			} );
		} );
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		for ( const widget of document.querySelectorAll( '.rvrt-widget' ) ) {
			syncWidgetTabs( widget );
		}
	} );
} )();
