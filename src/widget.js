/**
 * Dashboard widget of the Revision Retention plugin.
 *
 * Without this, the widget shows everything at once: the Site and Network
 * views one under the other, and the Daily, Weekly and Monthly charts each
 * under its own heading. With it, the views become tabs and the charts a
 * switch, one shown at a time.
 */
( () => {
	'use strict';

	const syncWidgetTabs = ( widget ) => {
		const list = widget.querySelector( '.rvrt-widget-tabs' );
		const tabs = [ ...widget.querySelectorAll( '.rvrt-widget-tab' ) ];

		if ( ! list || ! tabs.length ) {
			return;
		}

		const select = ( tab, focus ) => {
			for ( const other of tabs ) {
				const active = other === tab;
				const panel = document.getElementById( other.getAttribute( 'aria-controls' ) );

				other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				other.tabIndex = active ? 0 : -1;

				if ( panel ) {
					panel.hidden = ! active;
				}
			}

			if ( focus ) {
				tab.focus();
			}
		};

		tabs.forEach( ( tab, index ) => {
			tab.addEventListener( 'click', () => select( tab, false ) );

			tab.addEventListener( 'keydown', ( event ) => {
				// The arrows follow the reading direction, so in a right to
				// left language the right arrow goes back.
				const rtl = getComputedStyle( list ).direction === 'rtl';
				const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[ event.key ] ?? 0;
				const jump = { Home: 0, End: tabs.length - 1 }[ event.key ];

				if ( ! step && jump === undefined ) {
					return;
				}

				event.preventDefault();
				select( tabs[ jump ?? ( index + step + tabs.length ) % tabs.length ], true );
			} );
		} );

		// The tabs name the views from here on, and the views become their panels.
		for ( const title of widget.querySelectorAll( '.rvrt-widget-view-title' ) ) {
			title.hidden = true;
		}

		for ( const panel of widget.querySelectorAll( '.rvrt-widget-panel[data-tab]' ) ) {
			panel.setAttribute( 'role', 'tabpanel' );
			panel.setAttribute( 'aria-labelledby', panel.dataset.tab );
		}

		list.hidden = false;
		select( tabs.find( ( tab ) => tab.getAttribute( 'aria-selected' ) === 'true' ) ?? tabs[ 0 ], false );
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
		const buttons = [ ...widget.querySelectorAll( '.rvrt-period-button' ) ];

		if ( ! buttons.length ) {
			return;
		}

		// Compared with the buttons rather than put in a selector, so a stored
		// value that is no period at all is simply not found.
		const periods = buttons.map( ( button ) => button.dataset.period );
		const stored = remembered();
		const pressed = buttons.find( ( button ) => button.getAttribute( 'aria-pressed' ) === 'true' );
		const start = periods.includes( stored ) ? stored : pressed?.dataset.period ?? periods[ 0 ];

		for ( const button of buttons ) {
			button.addEventListener( 'click', () => {
				showPeriod( widget, button.dataset.period );
				remember( button.dataset.period );
			} );
		}

		// The switch names the period from here on.
		for ( const title of widget.querySelectorAll( '.rvrt-period-title' ) ) {
			title.hidden = true;
		}

		for ( const group of widget.querySelectorAll( '.rvrt-period-switch' ) ) {
			group.hidden = false;
		}

		showPeriod( widget, start );
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		for ( const widget of document.querySelectorAll( '.rvrt-widget' ) ) {
			syncWidgetTabs( widget );
			syncPeriods( widget );
		}
	} );
} )();
