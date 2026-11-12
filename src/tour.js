/**
 * The walkthrough of the settings screen, on WordPress's own admin pointers.
 *
 * The steps come from Admin\Tour, each with the tab it lives on. It starts on
 * its own when there are steps this user has not seen, which after an update
 * are only the new ones, and can be taken again in full from the link at the
 * bottom of the screen.
 */
( () => {
	'use strict';

	const data = window.rvrtTour;
	const $ = window.jQuery;
	const { _x, sprintf } = window.wp.i18n;
	const { speak } = window.wp.a11y;

	if ( ! data || ! $?.fn?.pointer ) {
		return;
	}

	/** The step's target, or null when it is not on this screen. */
	const targetOf = ( step ) => document.querySelector( step.target );

	/** The tab link a step lives on, when it lives on one. */
	const tabOf = ( step ) => ( step.tab ? document.getElementById( `rvrt-tab-${ step.tab }` ) : null );

	/** The tab open right now. */
	const currentTab = () => document.querySelector( '.rvrt-tabs a.nav-tab-active' );

	/** Steps that have something to point at here. */
	const present = ( steps ) => steps.filter( ( step ) => targetOf( step ) && ( ! step.tab || tabOf( step ) ) );

	/**
	 * A piece of the screen to show in a step. `log` is the newest entry of
	 * the log, or the example Admin\Tour renders while the log is empty. It is
	 * a picture of the entry, so it is inert: nothing in it takes focus.
	 */
	const preview = ( kind ) => {
		if ( kind !== 'log' ) {
			return null;
		}

		const real = document.querySelector( '.rvrt-timeline .rvrt-entry' );
		const entry = ( real ?? document.getElementById( 'rvrt-tour-log-example' )?.content.querySelector( '.rvrt-entry' ) )?.cloneNode( true );

		if ( ! entry ) {
			return null;
		}

		const box = document.createElement( 'div' );
		const label = document.createElement( 'p' );
		const list = document.createElement( 'ol' );

		box.className = 'rvrt-settings rvrt-pointer-preview';
		box.inert = true;
		label.className = 'rvrt-pointer-preview-label';
		label.textContent = real
			? _x( 'Your latest sweep', 'walkthrough log preview', 'revision-retention' )
			: _x( 'An example', 'walkthrough log preview', 'revision-retention' );
		list.className = 'rvrt-day-entries';
		list.append( entry );
		box.append( label, list );

		return box;
	};

	/** Store that this user completed or skipped the walkthrough. Not saved, it simply shows again. */
	const markSeen = () => {
		const body = new URLSearchParams( { action: data.action, _ajax_nonce: data.nonce } );

		data.pending = [];

		return window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } ).catch( () => {} );
	};

	/**
	 * Run the walkthrough. Escape or Skip ends it, and it ends on the tab and
	 * the element it started from.
	 */
	let running = false;

	const run = ( steps ) => {
		if ( running || ! steps.length ) {
			return false;
		}

		running = true;

		const startTab = currentTab();
		const returnFocus = document.activeElement;
		const reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		let current = null;
		let ended = false;

		const reposition = () => current?.element.pointer( 'reposition' );

		const onKey = ( event ) => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();
				end();
			}
		};

		const end = () => {
			if ( ended ) {
				return;
			}

			ended = true;
			running = false;
			current?.element.pointer( 'close' );
			document.removeEventListener( 'keydown', onKey, true );
			window.removeEventListener( 'resize', reposition );

			// settings.js switches tabs in place on a click, so nothing typed
			// in another tab is lost along the way.
			if ( startTab && startTab !== currentTab() ) {
				startTab.click();
			}

			returnFocus?.focus?.();
			markSeen();
		};

		const open = ( index ) => {
			const step = steps[ index ];
			const last = index + 1 === steps.length;
			const tab = tabOf( step );

			if ( tab && tab !== currentTab() ) {
				tab.click();
			}

			const target = targetOf( step );

			// The pointer opens below its target, so the target goes to the
			// upper third of the window, leaving room for the pointer under it.
			window.scrollTo( {
				top: Math.max( 0, target.getBoundingClientRect().top + window.scrollY - window.innerHeight / 3 ),
				behavior: reduced ? 'auto' : 'smooth',
			} );

			/* translators: 1: number of this step, 2: number of steps. */
			const eyebrow = sprintf( _x( 'Step %1$d of %2$d', 'walkthrough progress', 'revision-retention' ), index + 1, steps.length );
			const progress = Math.round( ( ( index + 1 ) / steps.length ) * 100 );

			// Built from nodes and text, never from an HTML string, so nothing
			// in a step can ever be read as markup.
			const content = () => {
				const node = ( tag, className, text ) => {
					const element = document.createElement( tag );

					if ( className ) {
						element.className = className;
					}

					if ( text ) {
						element.textContent = text;
					}

					return element;
				};

				const head = node( 'div', 'rvrt-pointer-head' );
				const bar = node( 'span', 'rvrt-pointer-progress' );
				const fill = node( 'span' );

				bar.setAttribute( 'aria-hidden', 'true' );
				fill.style.setProperty( '--rvrt-progress', `${ progress }%` );
				bar.append( fill );
				head.append( node( 'p', 'rvrt-pointer-eyebrow', eyebrow ), node( 'h3', '', step.title ), bar );

				return $( [ head, node( 'p', '', step.text ) ] );
			};

			$( target ).pointer( {
				pointerClass: 'wp-pointer rvrt-pointer',
				content,
				position: { edge: step.edge || 'top', align: step.align || 'left' },
				pointerWidth: 340,
				buttons: ( event, pointer ) => {
					current = pointer;

					const wrap = document.createElement( 'div' );
					wrap.className = 'rvrt-pointer-buttons';

					if ( ! last ) {
						const skip = document.createElement( 'button' );
						skip.type = 'button';
						skip.className = 'button-link';
						skip.textContent = _x( 'Skip walkthrough', 'walkthrough button', 'revision-retention' );
						skip.addEventListener( 'click', end );
						wrap.append( skip );
					}

					const next = document.createElement( 'button' );
					next.type = 'button';
					next.className = 'button button-primary rvrt-pointer-next';
					next.textContent = last
						? _x( 'Done', 'walkthrough button', 'revision-retention' )
						: _x( 'Next', 'walkthrough button', 'revision-retention' );
					next.addEventListener( 'click', () => {
						if ( last ) {
							end();

							return;
						}

						pointer.element.pointer( 'close' );
						open( index + 1 );
					} );
					wrap.append( next );

					// Core wraps what this returns with jQuery's .wrap().
					return $( wrap );
				},
			} ).pointer( 'open' );

			const shown = preview( step.preview );

			if ( shown && current?.pointer ) {
				current.pointer.find( '.wp-pointer-content > p' ).last().after( shown );
				reposition();
			}

			// The pointer is appended to <body>, far from the target in the
			// keyboard order, so focus is taken to it and its text read out.
			window.requestAnimationFrame( () => {
				[ ...document.querySelectorAll( '.rvrt-pointer .rvrt-pointer-next' ) ].pop()?.focus();
				speak( `${ eyebrow }. ${ step.title }. ${ step.text }`, 'polite' );
			} );
		};

		document.addEventListener( 'keydown', onKey, true );
		window.addEventListener( 'resize', reposition );
		open( 0 );

		return true;
	};

	// The way back in, for somebody who skipped it or wants it again.
	const wrap = document.querySelector( '.rvrt-settings' );

	if ( wrap ) {
		const replay = document.createElement( 'p' );
		replay.className = 'rvrt-tour-replay';

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link';
		button.textContent = _x( 'Take the walkthrough', 'walkthrough button', 'revision-retention' );
		button.addEventListener( 'click', () => run( present( data.steps ) ) );

		replay.append( button );
		wrap.append( replay );
	}

	// On the first visit, and after an update that added steps, only those.
	// A screen with nothing of them on it, a site that only follows the
	// network for instance, counts as seen.
	if ( data.pending.length && ! run( present( data.pending ) ) ) {
		markSeen();
	}
} )();
