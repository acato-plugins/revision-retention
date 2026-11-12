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

	/**
	 * A link the screen may follow: an http or https address, or null.
	 *
	 * The server already passes edit links through sanitize_url(), which
	 * drops javascript: and data: addresses. This keeps the link safe should
	 * a future path ever leave that out.
	 */
	const webUrl = ( value ) => {
		// An empty value would otherwise resolve to this screen's own address.
		if ( ! value ) {
			return null;
		}

		try {
			const url = new URL( String( value ?? '' ), window.location.href );

			return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
		} catch {
			return null;
		}
	};

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
	 * Switch off the fields that depend on a switch while it is off.
	 *
	 * The fields are disabled rather than the row made inert, so a screen
	 * reader still announces them, as unavailable, instead of skipping a
	 * setting that is plainly on screen. A disabled field is not submitted,
	 * though, and saving with the switch off must not wipe what was set, so
	 * they are enabled again on their way out; the server decides what the
	 * values mean. A field that was disabled from the start, such as a role
	 * that is always ticked, is left alone throughout.
	 */
	const syncDependent = ( toggleId, ids ) => {
		const toggle = document.getElementById( toggleId );

		if ( ! toggle ) {
			return;
		}

		// A checkbox on a site that owns its settings, a three way select when
		// the value can be inherited from the network instead, where Inherit
		// means whatever the network has.
		const isEnabled = () => {
			if ( toggle.type === 'checkbox' ) {
				return toggle.checked;
			}

			return toggle.value === '' ? toggle.dataset.inherited === '1' : toggle.value !== '0';
		};

		const rows = ids.map( ( id ) => document.getElementById( id )?.closest( 'tr' ) ).filter( Boolean );
		const fields = rows.flatMap( ( row ) =>
			[ ...row.querySelectorAll( 'input, select, textarea, button' ) ].filter( ( field ) => ! field.disabled )
		);

		const apply = () => {
			const enabled = isEnabled();

			for ( const row of rows ) {
				row.setAttribute( 'aria-disabled', enabled ? 'false' : 'true' );
			}

			for ( const field of fields ) {
				field.disabled = ! enabled;
			}
		};

		toggle.addEventListener( 'change', apply );
		toggle.form?.addEventListener( 'submit', () => {
			for ( const field of fields ) {
				field.disabled = false;
			}
		} );
		apply();
	};

	/** Show the days and the hour only while the sweep runs on chosen days. */
	const syncWeekdays = () => {
		const select = document.getElementById( 'rvrt-cron-interval' );
		const rows = document.querySelectorAll( '.rvrt-weekdays-row' );

		if ( ! select || ! rows.length ) {
			return;
		}

		const apply = () => {
			const interval = select.value === '' ? select.dataset.inherited : select.value;

			for ( const row of rows ) {
				row.hidden = interval !== 'weekdays';
			}
		};

		select.addEventListener( 'change', apply );
		apply();

		// Once at a time, every hour at some minutes past it, or every few
		// hours from a time: show only the fields and the words that fit.
		const repeat = document.getElementById( 'rvrt-cron-repeat' );

		if ( ! repeat ) {
			return;
		}

		const parts = document.querySelectorAll( '.rvrt-times [data-repeat]' );
		const applyRepeat = () => {
			for ( const part of parts ) {
				part.hidden = ! part.dataset.repeat.split( ' ' ).includes( repeat.value );
			}
		};

		repeat.addEventListener( 'change', applyRepeat );
		applyRepeat();
	};

	/**
	 * Fold the post types that hold nothing in and out of view.
	 *
	 * Every row is there without this; the quiet ones are folded away here,
	 * and the button that brings them back is shown.
	 */
	const syncQuietRows = () => {
		const button = document.querySelector( '.rvrt-toggle' );

		if ( ! button ) {
			return;
		}

		for ( const row of document.querySelectorAll( '.rvrt-quiet' ) ) {
			row.hidden = true;
		}

		button.closest( '.rvrt-toggle-wrap' )?.removeAttribute( 'hidden' );

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
		// Links only: a tab somebody may not open is a plain span, with no
		// panel behind it, and stays out of the keyboard order.
		const tabs = [ ...document.querySelectorAll( '.rvrt-tabs a.nav-tab' ) ];

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
				// The arrows follow the reading direction, so in a right to
				// left language the right arrow goes back.
				const rtl = getComputedStyle( tab ).direction === 'rtl';
				const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[ event.key ] ?? 0;
				const jump = { Home: 0, End: tabs.length - 1 }[ event.key ];

				if ( ! step && jump === undefined ) {
					return;
				}

				event.preventDefault();
				select( tabs[ jump ?? ( index + step + tabs.length ) % tabs.length ], true );
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

		// How long to wait for a batch before giving up on its answer. A batch
		// is a couple of hundred posts and takes seconds; this is for a request
		// that hangs, which would otherwise leave the bar busy for good. Giving
		// up does not stop the server, which may still finish the batch, so it
		// is set well past what any PHP time limit lets a batch run.
		const BATCH_TIMEOUT = 5 * 60 * 1000;

		// The WordPress spinner, beside the buttons while a sweep runs.
		const spinner = document.createElement( 'span' );

		spinner.className = 'spinner rvrt-spinner';
		stop.before( spinner );

		// The network's Preview and Sweep walk the sites from here, so they
		// only appear once this runs; without it, scheduling still works.
		for ( const button of form.querySelectorAll( '.rvrt-needs-script' ) ) {
			button.hidden = false;
		}

		// A sweep can touch thousands of posts. The list is there to be read,
		// so it stops at a length somebody would actually read and counts the
		// rest.
		const LIST_LIMIT = 500;

		let running = false;
		let cancelled = false;
		let listed = 0;
		let omitted = 0;
		let started = null;

		/*
		 * Marked unavailable rather than disabled. A disabled button drops the
		 * focus of whoever just pressed it to the top of the page; this one
		 * keeps it, says it is unavailable, and the running flag ignores it.
		 */
		const setBusy = ( busy ) => {
			running = busy;

			for ( const button of buttons ) {
				button.setAttribute( 'aria-disabled', busy ? 'true' : 'false' );
			}

			spinner.classList.toggle( 'is-active', busy );
			progress.hidden = false;

			if ( busy ) {
				stop.hidden = false;
				stop.removeAttribute( 'aria-disabled' );

				return;
			}

			// Hiding Stop while it has the focus would drop the focus too, so
			// it goes back to the button that started the sweep.
			const focusWasOnStop = document.activeElement === stop;

			stop.hidden = true;

			if ( focusWasOnStop ) {
				started?.focus();
			}
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

			// A notice that appears already filled in is often not read out,
			// so the outcome is spoken through the live region WordPress
			// keeps for exactly this, once.
			window.wp.a11y?.speak?.( message, kind === 'error' ? 'assertive' : 'polite' );
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
				const number = document.createElement( 'td' );
				const kept = document.createElement( 'td' );

				title.scope = 'row';

				const editUrl = webUrl( item.editUrl );

				if ( editUrl ) {
					const link = document.createElement( 'a' );

					link.href = editUrl;
					link.textContent = item.title || _x( '(no title)', 'affected posts list', 'revision-retention' );
					title.append( link );
				} else {
					title.textContent = item.title || _x( '(no title)', 'affected posts list', 'revision-retention' );
				}

				site.textContent = item.site ?? '';
				site.className = 'rvrt-col-site';
				type.textContent = item.type;
				number.textContent = item.revisions;
				number.className = 'rvrt-col-number';
				// What the post keeps, so the floor is visible next to the loss.
				kept.textContent = item.kept ?? '';
				kept.className = 'rvrt-col-number rvrt-kept';

				// The network list says which site each post belongs to; a
				// single site's list has no such column to fill.
				row.append( ...( network ? [ title, site, type, number, kept ] : [ title, type, number, kept ] ) );
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

		/*
		 * A request whose answer never arrived. It is told apart from a batch
		 * the server refused, because the server may well have done the work:
		 * the bar keeps where it got to, and the message says how to find out.
		 */
		const lost = ( mode ) => {
			const error = new Error(
				mode === 'run'
					? _x( 'The connection dropped before the server answered. The batch may still have run, so a reload of this page shows where the sweep stands.', 'sweep error', 'revision-retention' )
					: _x( 'The connection dropped before the server answered. A preview deletes nothing, so it is safe to start it again.', 'sweep error', 'revision-retention' )
			);

			error.lost = true;

			return error;
		};

		/*
		 * A batch the browser stopped waiting for. The server may be working on
		 * it still, and holds the sweep until it is done, so starting again
		 * straight away would only be told another sweep is running.
		 */
		const slow = ( mode ) => {
			const error = new Error(
				mode === 'run'
					? _x( 'The server took more than five minutes over this batch and may still be working on it. Reload this page in a few minutes to see where the sweep stands.', 'sweep error', 'revision-retention' )
					: _x( 'The server took more than five minutes over this batch. A preview deletes nothing, so it is safe to start it again in a few minutes.', 'sweep error', 'revision-retention' )
			);

			error.lost = true;

			return error;
		};

		const report = ( percent, message ) => {
			fill.style.inlineSize = `${ percent }%`;
			bar.setAttribute( 'aria-valuenow', String( percent ) );
			text.textContent = message;
		};

		const requestBatch = async ( mode, cursor, site ) => {
			let response;

			try {
				response = await fetch( form.dataset.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					signal: window.AbortSignal?.timeout?.( BATCH_TIMEOUT ),
					body: new URLSearchParams( {
						action: form.dataset.request,
						_wpnonce: form.dataset.nonce,
						mode,
						cursor: String( cursor ),
						site: String( site ),
					} ),
				} );
			} catch ( error ) {
				// The request went out, so the batch may well have run on the
				// server; only its answer was lost on the way back.
				throw error?.name === 'TimeoutError' ? slow( mode ) : lost( mode );
			}

			let text;

			try {
				text = await response.text();
			} catch ( error ) {
				throw error?.name === 'TimeoutError' ? slow( mode ) : lost( mode );
			}

			let payload;

			try {
				payload = JSON.parse( text );
			} catch {
				// An error page from the server or a proxy in between, a 500 or
				// a 502, is not the sweep's own refusal; say which it was.
				if ( ! response.ok ) {
					throw new Error(
						sprintf(
							/* translators: %s: HTTP status code, e.g. "502". */
							_x( 'The server answered with error %s instead of finishing the batch. The site\'s error log will say why.', 'sweep error', 'revision-retention' ),
							String( response.status )
						)
					);
				}

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
			if ( ! button || event.defaultPrevented ) {
				return;
			}

			// The buttons are only marked unavailable while a sweep runs, so
			// a press then must not post the form either.
			event.preventDefault();

			if ( running ) {
				return;
			}

			started = button;
			cancelled = false;
			resetList();
			setBusy( true );
			report( 0, _x( 'Starting…', 'sweep progress', 'revision-retention' ) );

			try {
				const { totals, finished } = await sweep( button.value );
				const done = describeDone( button.value, totals );

				report( finished ? 100 : Number( bar.getAttribute( 'aria-valuenow' ) ), '' );

				if ( ! finished ) {
					// A preview leaves nothing behind for the schedule to pick up;
					// what it can say is that its count is not the whole site.
					const rest = button.value === 'run'
						? _x( 'Stopped. The schedule will finish the rest.', 'sweep result', 'revision-retention' )
						: _x( 'Stopped part way, so this covers only the posts checked so far.', 'sweep result', 'revision-retention' );

					announce( `${ done } ${ rest }`, 'warning' );
				} else {
					// A preview that got all the way through has succeeded too;
					// its sentence already says nothing was deleted.
					announce( done, 'success' );
				}
			} catch ( error ) {
				report( error.lost ? Number( bar.getAttribute( 'aria-valuenow' ) ) : 0, '' );
				announce( error.message, error.lost ? 'warning' : 'error' );
			} finally {
				setBusy( false );
			}
		} );

		stop.addEventListener( 'click', () => {
			if ( cancelled ) {
				return;
			}

			cancelled = true;
			stop.setAttribute( 'aria-disabled', 'true' );
			text.textContent = _x( 'Stopping after this batch…', 'sweep progress', 'revision-retention' );
		} );
	};

	/**
	 * Ask before a press that reaches past this screen.
	 *
	 * Promoting a site's settings rewrites the defaults every other site
	 * starts from, and a network sweep deletes on every site, which is not
	 * something to do by brushing past a button. The question is asked in a
	 * modal dialog rather than the browser's own prompt, so it reads as part
	 * of the screen and can name the action on its button.
	 *
	 * A dialog does not block the way window.confirm() does, so the press is
	 * held back, and once the answer is yes the button is pressed again and
	 * let through, which keeps whatever the press does on its own.
	 */
	const syncConfirms = () => {
		for ( const control of document.querySelectorAll( '[data-confirm]' ) ) {
			let confirmed = false;

			control.addEventListener( 'click', async ( event ) => {
				// Marked unavailable, while a sweep runs: no question to ask,
				// and nothing to let through.
				if ( control.getAttribute( 'aria-disabled' ) === 'true' ) {
					event.preventDefault();
					event.stopImmediatePropagation();

					return;
				}

				if ( confirmed ) {
					confirmed = false;

					return;
				}

				event.preventDefault();

				if ( await ask( control ) ) {
					confirmed = true;
					control.click();
				}
			} );
		}
	};

	/** Put the question of a press to the user, and answer whether to go on. */
	const ask = ( control ) => {
		if ( ! window.HTMLDialogElement ) {
			// eslint-disable-next-line no-alert
			return Promise.resolve( window.confirm( control.dataset.confirm ) );
		}

		const dialog = document.createElement( 'dialog' );
		const title = document.createElement( 'h2' );
		const message = document.createElement( 'p' );
		const actions = document.createElement( 'form' );
		const cancel = document.createElement( 'button' );
		const proceed = document.createElement( 'button' );

		dialog.className = 'rvrt-dialog';
		dialog.setAttribute( 'aria-labelledby', 'rvrt-dialog-title' );
		dialog.setAttribute( 'aria-describedby', 'rvrt-dialog-message' );
		title.id = 'rvrt-dialog-title';
		title.className = 'rvrt-dialog-title';
		title.textContent = control.dataset.confirmTitle || control.textContent.trim();
		message.id = 'rvrt-dialog-message';
		message.className = 'rvrt-dialog-message';
		message.textContent = control.dataset.confirm;

		// A form of method dialog closes the dialog without a request, should
		// the submit handler below ever not get to it.
		actions.method = 'dialog';
		actions.className = 'rvrt-dialog-actions';
		cancel.className = 'button';
		cancel.value = 'cancel';
		cancel.textContent = _x( 'Cancel', 'button label', 'revision-retention' );
		proceed.className = 'button button-primary' + ( control.dataset.confirmDanger ? ' rvrt-dialog-danger' : '' );
		proceed.value = 'proceed';
		proceed.textContent = control.value && control.tagName === 'INPUT' ? control.value : control.textContent.trim();

		actions.append( cancel, proceed );
		dialog.append( title, message, actions );
		( control.closest( '.rvrt-settings' ) ?? document.body ).append( dialog );

		return new Promise( ( resolve ) => {
			let answered = false;

			// Every way out comes through here, once. The answer is taken from
			// the event that ends it rather than from the dialog's close event,
			// which Chrome holds back while the tab is in the background.
			const answer = ( yes ) => {
				if ( answered ) {
					return;
				}

				answered = true;
				dialog.close();
				dialog.remove();
				control.focus();
				resolve( yes );
			};

			actions.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				answer( event.submitter === proceed );
			} );

			// Escape.
			dialog.addEventListener( 'cancel', ( event ) => {
				event.preventDefault();
				answer( false );
			} );

			// A click on the backdrop lands on the dialog itself, outside its
			// box, and counts as a no.
			dialog.addEventListener( 'click', ( event ) => {
				const box = dialog.getBoundingClientRect();
				const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;

				if ( event.target === dialog && outside ) {
					answer( false );
				}
			} );

			dialog.addEventListener( 'close', () => answer( dialog.returnValue === 'proceed' ) );

			dialog.showModal();
			// The safe answer gets the focus.
			cancel.focus();
		} );
	};

	const MINUTE = 60;
	const HOUR = 60 * MINUTE;
	const DAY = 24 * HOUR;

	/*
	 * A number in a unit, written out the way the site's language does, e.g.
	 * "4 minuten". The browser knows every language's unit names and plurals,
	 * so these need no strings of their own to translate.
	 */
	const unit = ( name, value ) =>
		new Intl.NumberFormat( document.documentElement.lang || undefined, { style: 'unit', unit: name, unitDisplay: 'long' } ).format( value );

	/** Two units side by side, the larger first, e.g. "4 minutes 12 seconds". */
	const pair = ( larger, smaller ) =>
		new Intl.ListFormat( document.documentElement.lang || undefined, { style: 'narrow', type: 'unit' } ).format( [ larger, smaller ] );

	const seconds = ( value ) => unit( 'second', value );
	const minutes = ( value ) => unit( 'minute', value );
	const hours = ( value ) => unit( 'hour', value );
	const days = ( value ) => unit( 'day', value );

	/**
	 * A number of seconds as the two largest units it has.
	 *
	 * Seconds are kept while under an hour, so the countdown visibly moves;
	 * beyond that a ticking second is noise next to the hours.
	 */
	const duration = ( total ) => {
		if ( total < MINUTE ) {
			return seconds( total );
		}

		if ( total < HOUR ) {
			return pair( minutes( Math.floor( total / MINUTE ) ), seconds( total % MINUTE ) );
		}

		if ( total < DAY ) {
			return pair( hours( Math.floor( total / HOUR ) ), minutes( Math.floor( ( total % HOUR ) / MINUTE ) ) );
		}

		return pair( days( Math.floor( total / DAY ) ), hours( Math.floor( ( total % DAY ) / HOUR ) ) );
	};

	/**
	 * Keep the times until the next sweep and the next cron events counting.
	 *
	 * The server says how many seconds were left when it rendered the page,
	 * which is counted down from here, so a visitor whose clock is off still
	 * sees the right time. Once one runs out, its sentence turns to the one
	 * for a moment that has passed and counts up from there, as the server
	 * would have rendered it on a reload.
	 */
	const syncCountdowns = () => {
		const countdowns = [ ...document.querySelectorAll( '.rvrt-countdown[data-left]' ) ].map( ( element ) => ( {
			element,
			left: Number( element.dataset.left ),
			ahead: element.dataset.ahead,
			behind: element.dataset.behind,
		} ) );

		if ( ! countdowns.length ) {
			return;
		}

		const start = Date.now();
		let timer = 0;

		const tick = () => {
			const elapsed = ( Date.now() - start ) / 1000;

			for ( const { element, left, ahead, behind } of countdowns ) {
				const remaining = Math.ceil( left - elapsed );
				const sentence =
					remaining > 0
						? sprintf( ahead, duration( remaining ) )
						: sprintf( behind, duration( Math.max( 1, -remaining ) ) );

				// Beyond the hour the text changes once a minute; it is only
				// written when it does, so nothing re-reads an unchanged line.
				if ( element.textContent !== sentence ) {
					element.textContent = sentence;
				}
			}
		};

		// Counted from the clock rather than from the ticks, so a tab in the
		// background can stop ticking and still be right when it comes back.
		const run = () => {
			window.clearInterval( timer );
			timer = 0;

			if ( document.hidden ) {
				return;
			}

			tick();
			timer = window.setInterval( tick, 1000 );
		};

		document.addEventListener( 'visibilitychange', run );
		run();
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		syncTabs();
		syncConfirms();
		syncDependent( 'rvrt-cron-enabled', [ 'rvrt-cron-interval', 'rvrt-cron-days', 'rvrt-cron-repeat', 'rvrt-max-deletions', 'rvrt-batch-size' ] );
		syncWeekdays();
		syncDependent( 'rvrt-log-enabled', [ 'rvrt-log-retention' ] );
		syncDependent( 'rvrt-dashboard-widget', [ 'rvrt-widget-roles' ] );
		syncQuietRows();
		syncCountdowns();

		if ( window.fetch ) {
			for ( const form of document.querySelectorAll( '.rvrt-sweep[data-ajax-url]' ) ) {
				syncSweep( form );
			}
		}
	} );
} )();
