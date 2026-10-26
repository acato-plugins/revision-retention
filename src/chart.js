/**
 * Activity chart of the Revision Retention plugin.
 *
 * Every bar is a button, for the keyboard: the arrow keys, Home and End move
 * between them, so the chart is one stop in the tab order rather than one per
 * bar, and focusing a bar shows its tooltip.
 *
 * A pointer does not have to hit a bar, which can be a few pixels wide and
 * short. Anywhere over the chart shows the bar whose column it is in, and a
 * click anywhere pins that one, which is what makes it work on a touch
 * screen. A column with nothing removed has no tooltip, and is no stop for
 * the keyboard either. A pinned tooltip stays until a click
 * elsewhere or Escape. The tooltip can itself be pointed at without going
 * away, so it can be read at any zoom.
 */
( () => {
	'use strict';

	const syncChart = ( chart ) => {
		const area = chart.querySelector( '.rvrt-chart-bars' );
		const columns = [ ...chart.querySelectorAll( '.rvrt-chart-bar' ) ];
		const bars = columns.filter( ( column ) => ! column.classList.contains( 'is-empty' ) );
		const tip = chart.querySelector( '.rvrt-chart-tip' );

		if ( ! area || ! bars.length || ! tip ) {
			return;
		}

		let pinned = null;

		const show = ( bar ) => {
			tip.querySelector( '.rvrt-chart-tip-when' ).textContent = bar.dataset.when;
			tip.querySelector( '.rvrt-chart-tip-count' ).textContent = bar.dataset.count;
			tip.hidden = false;

			// Centred over the bar, but kept inside the chart at either end. The
			// measurements are physical, left to right whatever the language, so
			// the position is too.
			const frame = chart.getBoundingClientRect();
			const box = bar.getBoundingClientRect();
			const half = tip.offsetWidth / 2;
			const centre = box.left - frame.left + box.width / 2;

			tip.style.left = `${ Math.min( Math.max( centre - half, 0 ), frame.width - tip.offsetWidth ) }px`;
			tip.style.bottom = `${ frame.bottom - box.top + 6 }px`;

			for ( const other of bars ) {
				other.classList.toggle( 'is-active', other === bar );
			}
		};

		const hide = () => {
			tip.hidden = true;
			pinned = null;

			for ( const bar of bars ) {
				bar.classList.remove( 'is-active' );
			}
		};

		/** The bar whose column a point falls in, or null for an empty one. */
		const nearest = ( x ) => {
			let best = columns[ 0 ];
			let distance = Infinity;

			for ( const column of columns ) {
				const box = column.getBoundingClientRect();
				const away = Math.abs( box.left + box.width / 2 - x );

				if ( away < distance ) {
					best = column;
					distance = away;
				}
			}

			return bars.includes( best ) ? best : null;
		};

		const move = ( bar ) => {
			for ( const other of bars ) {
				other.tabIndex = other === bar ? 0 : -1;
			}

			bar.focus();
		};

		chart.addEventListener( 'pointermove', ( event ) => {
			// Over the tooltip itself, or with one pinned: leave it where it is.
			if ( pinned || tip.contains( event.target ) ) {
				return;
			}

			const bar = nearest( event.clientX );

			if ( bar ) {
				show( bar );
			} else if ( ! bars.includes( document.activeElement ) ) {
				hide();
			}
		} );

		chart.addEventListener( 'pointerleave', () => {
			if ( ! pinned && ! bars.includes( document.activeElement ) ) {
				hide();
			}
		} );

		area.addEventListener( 'click', ( event ) => {
			// A click from the keyboard has no position; it is the focused bar.
			const bar = event.detail === 0 ? event.target.closest( '.rvrt-chart-bar' ) : nearest( event.clientX );

			event.stopPropagation();

			if ( ! bar ) {
				hide();

				return;
			}

			if ( pinned === bar ) {
				hide();

				return;
			}

			pinned = bar;
			show( bar );
		} );

		bars.forEach( ( bar, index ) => {
			bar.addEventListener( 'focus', () => show( bar ) );

			bar.addEventListener( 'blur', () => {
				if ( ! pinned ) {
					hide();
				}
			} );

			bar.addEventListener( 'keydown', ( event ) => {
				// The arrows go the way the bars run, which is the other way
				// round in a right to left language.
				const ahead = getComputedStyle( area ).direction === 'rtl' ? -1 : 1;
				const target = {
					ArrowRight: bars[ index + ahead ],
					ArrowLeft: bars[ index - ahead ],
					Home: bars[ 0 ],
					End: bars[ bars.length - 1 ],
				}[ event.key ];

				if ( target ) {
					event.preventDefault();
					pinned = null;
					move( target );
				}
			} );
		} );

		// Escape closes the tooltip wherever focus is, as content shown on
		// hover has to be dismissable without moving the pointer.
		document.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' && ! tip.hidden ) {
				hide();
			}
		} );

		document.addEventListener( 'click', ( event ) => {
			if ( pinned && ! chart.contains( event.target ) ) {
				hide();
			}
		} );
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		for ( const chart of document.querySelectorAll( '.rvrt-chart' ) ) {
			syncChart( chart );
		}
	} );
} )();
