/**
 * Settings screen of the Revision Retention plugin.
 *
 * Everything here is an enhancement. The tabs are links and work on their own,
 * the sweep form posts and does a batch on its own, and the fields submit
 * whether or not any of this runs.
 */
( () => {
	'use strict';

	const strings = window.rvrtSettings ?? {};

	/** Fill a %1$s / %2$s template, the way the PHP side writes them. */
	const format = ( template, first, second ) =>
		String( template ?? '' ).replace( '%1$s', first ).replace( '%2$s', second );

	/**
	 * Dim the scheduling fields while the scheduled sweep is switched off.
	 *
	 * Cosmetic only: the values are still submitted and still saved, and the
	 * server decides what they mean.
	 */
	const syncSchedule = () => {
		const toggle = document.getElementById( 'rvrt-cron-enabled' );

		if ( ! toggle ) {
			return;
		}

		// A checkbox on a site that owns its settings, a three way select when
		// the value can be inherited from the network instead.
		const isEnabled = () =>
			toggle.type === 'checkbox' ? toggle.checked : toggle.value !== '0';

		const apply = () => {
			const enabled = isEnabled();

			for ( const id of [ 'rvrt-cron-interval', 'rvrt-max-deletions', 'rvrt-batch-size' ] ) {
				const row = document.getElementById( id )?.closest( 'tr' );

				row?.setAttribute( 'aria-disabled', enabled ? 'false' : 'true' );
			}
		};

		toggle.addEventListener( 'change', apply );
		apply();
	};

	/** Fold the post types that hold nothing in and out of view. */
	const syncQuietRows = () => {
		const button = document.querySelector( '.rvrt-toggle' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', () => {
			const shown = button.dataset.shown === '1';

			for ( const row of document.querySelectorAll( '.rvrt-quiet' ) ) {
				row.hidden = shown;
			}

			button.dataset.shown = shown ? '0' : '1';
			button.textContent = shown ? button.dataset.show : button.dataset.hide;
		} );
	};

	/**
	 * Switch sections in place.
	 *
	 * Following a tab link reloads the screen with that section open, which is
	 * the fallback. Handling the click here keeps edits made in the other
	 * sections, which a reload would throw away, since every section is part of
	 * the one form.
	 */
	const syncTabs = () => {
		const tabs = [ ...document.querySelectorAll( '.rvrt-tabs .nav-tab' ) ];

		if ( ! tabs.length ) {
			return;
		}

		const select = ( tab, focus ) => {
			const slug = tab.getAttribute( 'aria-controls' ).replace( 'rvrt-panel-', '' );

			for ( const other of tabs ) {
				const active = other === tab;

				other.classList.toggle( 'nav-tab-active', active );
				other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				other.setAttribute( 'tabindex', active ? '0' : '-1' );
			}

			for ( const panel of document.querySelectorAll( '.rvrt-panel' ) ) {
				panel.hidden = panel.dataset.tab !== slug;
			}

			// The Save button belongs to the settings form, which the sweep
			// section is not part of.
			for ( const row of document.querySelectorAll( '.rvrt-submit' ) ) {
				row.hidden = slug === 'sweep';
			}

			for ( const input of document.querySelectorAll( '.rvrt-current-tab' ) ) {
				input.value = slug;
			}

			window.history?.replaceState?.( null, '', tab.href );

			if ( focus ) {
				tab.focus();
			}
		};

		tabs.forEach( ( tab, index ) => {
			tab.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				select( tab, false );
			} );

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

	/**
	 * Drive a sweep batch after batch, showing how far it has got.
	 *
	 * Without this the form posts once, does a single batch and reloads. With
	 * it the whole site is covered in one go: a preview reports what the policy
	 * would take everywhere rather than in the first batch only, and a real run
	 * keeps going until there is nothing left. Either can be stopped, and
	 * whatever is left is still the schedule's to finish.
	 */
	const syncSweep = () => {
		const form = document.querySelector( '.rvrt-sweep[data-ajax-url]' );

		if ( ! form || ! window.fetch ) {
			return;
		}

		const buttons = [ ...form.querySelectorAll( 'button[name="mode"]' ) ];
		const stop = form.querySelector( '.rvrt-stop' );
		const progress = form.querySelector( '.rvrt-progress' );
		const bar = form.querySelector( '.rvrt-progress-bar' );
		const fill = form.querySelector( '.rvrt-progress-fill' );
		const text = form.querySelector( '.rvrt-progress-text' );
		const affected = form.querySelector( '.rvrt-affected' );
		const affectedBody = affected.querySelector( 'tbody' );
		const affectedMore = affected.querySelector( '.rvrt-affected-more' );

		// A sweep can touch thousands of posts. The list is there to be read,
		// so it stops at a length somebody would actually read and counts the
		// rest.
		const LIST_LIMIT = 500;

		let running = false;
		let cancelled = false;
		let listed = 0;
		let omitted = 0;

		const setBusy = ( busy ) => {
			running = busy;

			for ( const button of buttons ) {
				button.disabled = busy;
			}

			stop.hidden = ! busy;
			stop.disabled = false;
			progress.hidden = false;
		};

		const resetList = () => {
			affectedBody.replaceChildren();
			affected.hidden = true;
			affectedMore.hidden = true;
			listed = 0;
			omitted = 0;
		};

		/** Add this batch's posts to the list, titles as text rather than markup. */
		const addToList = ( items ) => {
			for ( const item of items ) {
				if ( listed >= LIST_LIMIT ) {
					omitted += 1;
					continue;
				}

				const row = document.createElement( 'tr' );
				const title = document.createElement( 'th' );
				const type = document.createElement( 'td' );
				const count = document.createElement( 'td' );

				title.scope = 'row';

				if ( item.editUrl ) {
					const link = document.createElement( 'a' );

					link.href = item.editUrl;
					link.textContent = item.title || strings.untitled;
					title.append( link );
				} else {
					title.textContent = item.title || strings.untitled;
				}

				type.textContent = item.type;
				count.textContent = item.revisions;
				count.className = 'rvrt-col-number';

				row.append( title, type, count );
				affectedBody.append( row );
				listed += 1;
			}

			if ( listed > 0 ) {
				affected.hidden = false;
			}

			if ( omitted > 0 ) {
				affectedMore.hidden = false;
				affectedMore.textContent = format( strings.more, omitted );
			}
		};

		const report = ( percent, message ) => {
			fill.style.inlineSize = `${ percent }%`;
			bar.setAttribute( 'aria-valuenow', String( percent ) );
			text.textContent = message;
		};

		const requestBatch = async ( mode, cursor ) => {
			const response = await fetch( form.dataset.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams( {
					action: 'rvrt_run_sweep',
					_wpnonce: form.dataset.nonce,
					mode,
					cursor: String( cursor ),
				} ),
			} );

			const payload = await response.json();

			if ( ! payload?.success ) {
				throw new Error( payload?.data?.message ?? strings.failed );
			}

			return payload.data;
		};

		const sweep = async ( mode ) => {
			const totals = { posts: 0, revisions: 0 };
			let cursor = 0;
			let finished = false;

			while ( ! finished && ! cancelled ) {
				// Each batch is awaited before the next is asked for, so the
				// site is never handed more than one sweep at a time.
				// eslint-disable-next-line no-await-in-loop
				const batch = await requestBatch( mode, cursor );

				totals.posts += batch.posts;
				totals.revisions += batch.revisions;
				cursor = batch.cursor;
				finished = batch.finished;

				addToList( batch.items ?? [] );

				report(
					batch.progress,
					format(
						mode === 'run' ? strings.runBusy : strings.previewBusy,
						totals.revisions,
						totals.posts
					)
				);
			}

			return { totals, finished };
		};

		form.addEventListener( 'click', async ( event ) => {
			const button = event.target.closest( 'button[name="mode"]' );

			if ( ! button || running ) {
				return;
			}

			event.preventDefault();
			cancelled = false;
			resetList();
			setBusy( true );
			report( 0, strings.starting );

			try {
				const { totals, finished } = await sweep( button.value );
				const done = format(
					button.value === 'run' ? strings.runDone : strings.previewDone,
					totals.revisions,
					totals.posts
				);

				report(
					finished ? 100 : Number( bar.getAttribute( 'aria-valuenow' ) ),
					finished ? done : `${ done } ${ strings.stoppedShort }`
				);
			} catch ( error ) {
				report( 0, error.message );
			} finally {
				setBusy( false );
			}
		} );

		stop.addEventListener( 'click', () => {
			cancelled = true;
			stop.disabled = true;
			text.textContent = strings.stopping;
		} );
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		syncTabs();
		syncSchedule();
		syncQuietRows();
		syncSweep();
	} );
} )();
