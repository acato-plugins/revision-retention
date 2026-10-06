/**
 * Settings screen of the Revision Retention plugin.
 *
 * Everything here is an enhancement. The tabs are links and work on their own,
 * the sweep form posts and does a batch on its own, and the fields submit
 * whether or not any of this runs.
 */
( () => {
	'use strict';

	const { _x, _nx, sprintf } = window.wp.i18n;

	/**
	 * Present a count the way the site writes numbers.
	 *
	 * The PHP side runs every count through number_format_i18n(), which is why
	 * these strings take %s rather than %d: the placeholder is filled with an
	 * already formatted string, not a bare integer.
	 */
	const count = ( value ) =>
		Number( value ).toLocaleString( document.documentElement.lang || undefined );

	/*
	 * The counts a sentence is built from, each in the plural its number asks
	 * for. A sentence with two counts would otherwise have to agree with both
	 * in one string, which no language's plural rules can express.
	 */
	const revisions = ( value ) =>
		/* translators: %s: number of revisions. */
		sprintf( _nx( '%s revision', '%s revisions', value, 'sweep count', 'revision-retention' ), count( value ) );

	const posts = ( value ) =>
		/* translators: %s: number of posts. */
		sprintf( _nx( '%s post', '%s posts', value, 'sweep count', 'revision-retention' ), count( value ) );

	/** What a sweep has done so far, for the small text beside the bar. */
	const describeBusy = ( mode, totals ) =>
		sprintf(
			mode === 'run'
				? /* translators: 1: number of revisions, e.g. "3 revisions", 2: number of posts, e.g. "120 posts". */
				  _x( '%1$s removed, %2$s checked', 'sweep progress', 'revision-retention' )
				: /* translators: 1: number of revisions, e.g. "3 revisions", 2: number of posts, e.g. "120 posts". */
				  _x( '%1$s found, %2$s checked', 'sweep progress', 'revision-retention' ),
			revisions( totals.revisions ),
			posts( totals.checked )
		);

	/** What a sweep did, for the notice once it ends. */
	const describeDone = ( mode, totals ) =>
		sprintf(
			mode === 'run'
				? /* translators: 1: number of revisions, e.g. "3 revisions", 2: number of posts, e.g. "2 posts". */
				  _x( '%1$s removed from %2$s.', 'sweep result', 'revision-retention' )
				: /* translators: 1: number of revisions, e.g. "3 revisions", 2: number of posts, e.g. "2 posts". */
				  _x( '%1$s would be removed from %2$s. Nothing has been deleted.', 'sweep result', 'revision-retention' ),
			revisions( totals.revisions ),
			posts( totals.posts )
		);

	/**
	 * Dim the fields that depend on a switch while it is off.
	 *
	 * Cosmetic only: the values are still submitted and still saved, and the
	 * server decides what they mean.
	 */
	const syncDependent = ( toggleId, ids ) => {
		const toggle = document.getElementById( toggleId );

		if ( ! toggle ) {
			return;
		}

		// A checkbox on a site that owns its settings, a three way select when
		// the value can be inherited from the network instead.
		const isEnabled = () =>
			toggle.type === 'checkbox' ? toggle.checked : toggle.value !== '0';

		const apply = () => {
			const enabled = isEnabled();

			for ( const id of ids ) {
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
	const syncSweep = ( form ) => {
		const network = form.dataset.scope === 'network';
		const buttons = [ ...form.querySelectorAll( 'button[name="mode"]' ) ];
		const stop = form.querySelector( '.rvrt-stop' );
		const progress = form.querySelector( '.rvrt-progress' );
		const bar = form.querySelector( '.rvrt-progress-bar' );
		const fill = form.querySelector( '.rvrt-progress-fill' );
		const text = form.querySelector( '.rvrt-progress-text' );
		const result = form.querySelector( '.rvrt-sweep-result' );
		const resultText = result.querySelector( 'p' );
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

		/**
		 * State the outcome where it will actually be read.
		 *
		 * The running commentary belongs in small text beside the bar, but the
		 * sentence that says whether anything was deleted is the one people are
		 * looking for, so it gets a notice of its own.
		 */
		const announce = ( message, kind ) => {
			result.className = `rvrt-sweep-result notice inline notice-${ kind }`;
			resultText.textContent = message;
			result.hidden = false;
		};

		const resetList = () => {
			result.hidden = true;
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
				const site = document.createElement( 'td' );
				const type = document.createElement( 'td' );
				const count = document.createElement( 'td' );
				const kept = document.createElement( 'td' );

				title.scope = 'row';

				if ( item.editUrl ) {
					const link = document.createElement( 'a' );

					link.href = item.editUrl;
					link.textContent = item.title || _x( '(no title)', 'affected posts list', 'revision-retention' );
					title.append( link );
				} else {
					title.textContent = item.title || _x( '(no title)', 'affected posts list', 'revision-retention' );
				}

				site.textContent = item.site ?? '';
				type.textContent = item.type;
				count.textContent = item.revisions;
				count.className = 'rvrt-col-number';
				// What the post keeps, so the floor is visible next to the loss.
				kept.textContent = item.kept ?? '';
				kept.className = 'rvrt-col-number rvrt-kept';

				// The network list says which site each post belongs to; a
				// single site's list has no such column to fill.
				row.append( ...( network ? [ title, site, type, count, kept ] : [ title, type, count, kept ] ) );
				affectedBody.append( row );
				listed += 1;
			}

			if ( listed > 0 ) {
				affected.hidden = false;
			}

			if ( omitted > 0 ) {
				affectedMore.hidden = false;
				affectedMore.textContent = sprintf(
					/* translators: %s: number of posts. */
					_nx( 'And %s more post.', 'And %s more posts.', omitted, 'affected posts list', 'revision-retention' ),
					count( omitted )
				);
			}
		};

		const report = ( percent, message ) => {
			fill.style.inlineSize = `${ percent }%`;
			bar.setAttribute( 'aria-valuenow', String( percent ) );
			text.textContent = message;
		};

		const requestBatch = async ( mode, cursor, site ) => {
			const response = await fetch( form.dataset.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: new URLSearchParams( {
					action: form.dataset.request,
					_wpnonce: form.dataset.nonce,
					mode,
					cursor: String( cursor ),
					site: String( site ),
				} ),
			} );

			const text = await response.text();
			let payload;

			try {
				payload = JSON.parse( text );
			} catch {
				// Anything that prints before the response lands in front of it
				// and stops it being data at all. Say so, rather than showing a
				// parser's complaint about an angle bracket.
				throw new Error(
					_x( 'The server answered with something other than data, which usually means another plugin printed a PHP warning. The site\'s error log will say what it was.', 'sweep error', 'revision-retention' )
				);
			}

			if ( ! payload?.success ) {
				throw new Error(
					payload?.data?.message ?? _x( 'The sweep could not be completed.', 'sweep error', 'revision-retention' )
				);
			}

			return payload.data;
		};

		const sweep = async ( mode ) => {
			// Checked is every post a batch looked at, which is what keeps the
			// progress moving; posts is only the ones that lost something.
			const totals = { checked: 0, posts: 0, revisions: 0 };
			let cursor = 0;
			let site = 0;
			let finished = false;

			while ( ! finished && ! cancelled ) {
				// Each batch is awaited before the next is asked for, so the
				// site is never handed more than one sweep at a time.
				// eslint-disable-next-line no-await-in-loop
				const batch = await requestBatch( mode, cursor, site );

				totals.checked += batch.posts;
				totals.posts += batch.affected;
				totals.revisions += batch.revisions;
				cursor = batch.cursor;
				site = batch.site ?? 0;
				finished = batch.finished;

				addToList( batch.items ?? [] );
				report( batch.progress, describeBusy( mode, totals ) );
			}

			return { totals, finished };
		};

		form.addEventListener( 'click', async ( event ) => {
			const button = event.target.closest( 'button[name="mode"]' );

			// A press whose confirmation was declined has its default
			// prevented by syncConfirms() before it gets here.
			if ( ! button || running || event.defaultPrevented ) {
				return;
			}

			event.preventDefault();
			cancelled = false;
			resetList();
			setBusy( true );
			report( 0, _x( 'Starting…', 'sweep progress', 'revision-retention' ) );

			try {
				const { totals, finished } = await sweep( button.value );
				const done = describeDone( button.value, totals );

				report( finished ? 100 : Number( bar.getAttribute( 'aria-valuenow' ) ), '' );

				if ( ! finished ) {
					announce( `${ done } ${ _x( 'Stopped. The schedule will finish the rest.', 'sweep result', 'revision-retention' ) }`, 'warning' );
				} else {
					announce( done, button.value === 'run' ? 'success' : 'info' );
				}
			} catch ( error ) {
				report( 0, '' );
				announce( error.message, 'error' );
			} finally {
				setBusy( false );
			}
		} );

		stop.addEventListener( 'click', () => {
			cancelled = true;
			stop.disabled = true;
			text.textContent = _x( 'Stopping after this batch…', 'sweep progress', 'revision-retention' );
		} );
	};

	/**
	 * Ask before a press that reaches past this screen.
	 *
	 * Promoting a site's settings rewrites the defaults every other site
	 * starts from, which is not something to do by brushing past a button.
	 */
	const syncConfirms = () => {
		for ( const control of document.querySelectorAll( '[data-confirm]' ) ) {
			control.addEventListener( 'click', ( event ) => {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( control.dataset.confirm ) ) {
					event.preventDefault();
				}
			} );
		}
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		syncTabs();
		syncConfirms();
		syncDependent( 'rvrt-cron-enabled', [ 'rvrt-cron-interval', 'rvrt-max-deletions', 'rvrt-batch-size' ] );
		syncDependent( 'rvrt-log-enabled', [ 'rvrt-log-retention' ] );
		syncQuietRows();

		if ( window.fetch ) {
			for ( const form of document.querySelectorAll( '.rvrt-sweep[data-ajax-url]' ) ) {
				syncSweep( form );
			}
		}
	} );
} )();
