/**
 * Dashboard widget of the Revision Retention plugin.
 *
 * Makes the Site and Network tabs a network administrator gets in the widget
 * switch between the two views, and the Daily, Weekly and Monthly buttons
 * switch between the charts.
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

	// Remembered in this browser only, as a convenience: without it, the
	// widget simply starts on the period that suits the schedule.
	const STORE = 'rvrt-widget-period';

	const remembered = () => {
		try {
			return window.localStorage.getItem( STORE );
		} catch {
			return null;
		}
	};

	const remember = ( period ) => {
		try {
			window.localStorage.setItem( STORE, period );
		} catch {
			// Storage can be blocked; the choice then lasts for this page only.
		}
	};

	/** Show one period's totals and chart, for every view in the widget. */
	const showPeriod = ( widget, period ) => {
		for ( const view of widget.querySelectorAll( '.rvrt-log-recent' ) ) {
			for ( const button of view.querySelectorAll( '.rvrt-period-button' ) ) {
				button.setAttribute( 'aria-pressed', button.dataset.period === period ? 'true' : 'false' );
			}

			for ( const block of view.querySelectorAll( '.rvrt-period' ) ) {
				block.hidden = block.dataset.period !== period;
			}
		}
	};

	const syncPeriods = ( widget ) => {
		const buttons = widget.querySelectorAll( '.rvrt-period-button' );

		if ( ! buttons.length ) {
			return;
		}

		const stored = remembered();

		if ( stored && widget.querySelector( `.rvrt-period[data-period="${ stored }"]` ) ) {
			showPeriod( widget, stored );
		}

		for ( const button of buttons ) {
			button.addEventListener( 'click', () => {
				showPeriod( widget, button.dataset.period );
				remember( button.dataset.period );
			} );
		}
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		for ( const widget of document.querySelectorAll( '.rvrt-widget' ) ) {
			syncWidgetTabs( widget );
			syncPeriods( widget );
		}
	} );
} )();
