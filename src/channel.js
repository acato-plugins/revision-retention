/**
 * Tells the plugin's other open tabs, on this site, that something changed.
 *
 * Only signals pass between the tabs: that a sweep started or ended, or that
 * the settings were saved. The receiving tab shows a notice, or marks its
 * sweep buttons unavailable, and never takes a message as data or as leave to
 * do anything; the server checks every request as it always does. Without
 * BroadcastChannel nothing is sent or heard, and each tab works on its own.
 *
 * Shared by the settings screen and the Dashboard widget as a script of its
 * own, since an import would split it into a module chunk that a classic
 * script cannot load.
 */
( () => {
	'use strict';

	const { _x, _nx, sprintf } = window.wp.i18n;

	const VERSION = 1;
	const MODES = [ 'run', 'preview' ];

	// What each message may carry, beside its type: anything else is not ours.
	const valid = {
		'sweep:start': ( data ) => MODES.includes( data.mode ),
		'sweep:end': ( data ) => MODES.includes( data.mode ),
		'settings:saved': ( data ) => typeof data.stamp === 'string' && /^[a-f0-9]{1,64}$/.test( data.stamp ),
	};

	// Which tab a message came from, so a receiver can tell two senders apart.
	const tab = window.crypto?.randomUUID?.() ?? `${ Date.now() }-${ Math.random() }`;

	const accept = ( data ) =>
		data !== null &&
		typeof data === 'object' &&
		data.version === VERSION &&
		typeof data.tab === 'string' &&
		// One page can hold two channels of the same name, which hear each
		// other; what this tab said itself is not news to it.
		data.tab !== tab &&
		Object.hasOwn( valid, data.type ) &&
		valid[ data.type ]( data );

	/**
	 * Listen on, and post to, one channel.
	 *
	 * The channel closes when the page is hidden for good or put in the
	 * back/forward cache, which a page with an open channel is not let into,
	 * and opens again when the page comes back from it.
	 *
	 * @param {string}   name                The channel, already scoped to the site.
	 * @param {Function} onMessage           Called with each message that is ours.
	 * @param {Object}   [options]
	 * @param {Function} [options.beforeClose] Called just before the page closes the channel, to post a last word.
	 * @return {{post: Function, stop: Function}} Post a message; stop listening.
	 */
	const open = ( name, onMessage, { beforeClose } = {} ) => {
		if ( ! name || ! ( 'BroadcastChannel' in window ) ) {
			return { post: () => {}, stop: () => {} };
		}

		let channel = null;

		const receive = ( event ) => accept( event.data ) && onMessage( event.data );

		const connect = () => {
			channel = new BroadcastChannel( name );
			channel.addEventListener( 'message', receive );
		};

		const disconnect = () => {
			channel?.close();
			channel = null;
		};

		const post = ( type, extra = {} ) => channel?.postMessage( { ...extra, type, version: VERSION, tab } );

		const onHide = () => {
			beforeClose?.( post );
			disconnect();
		};

		const onShow = ( event ) => event.persisted && ! channel && connect();

		const stop = () => {
			window.removeEventListener( 'pagehide', onHide );
			window.removeEventListener( 'pageshow', onShow );
			disconnect();
		};

		window.addEventListener( 'pagehide', onHide );
		window.addEventListener( 'pageshow', onShow );
		connect();

		return { post, stop };
	};

	/**
	 * Read a message out once, when the tab is in view.
	 *
	 * A tab in the background has nobody looking at it, so what it heard is
	 * said when it comes to the front rather than at once, and only the last
	 * thing it heard.
	 */
	let pending = '';

	const speakNow = () => {
		if ( document.hidden || ! pending ) {
			return;
		}

		window.wp?.a11y?.speak?.( pending, 'polite' );
		pending = '';
	};

	document.addEventListener( 'visibilitychange', speakNow );

	const speak = ( message ) => {
		pending = message;
		speakNow();
	};

	/** Show a notice the server rendered hidden, and read it out the first time. */
	const reveal = ( notice ) => {
		if ( ! notice || ! notice.hidden ) {
			return;
		}

		notice.hidden = false;
		speak( notice.textContent.replace( /\s+/g, ' ' ).trim() );
	};

	// A notice that asks for a reload has a button that does it.
	document.addEventListener( 'click', ( event ) => event.target.closest?.( '.rvrt-reload' ) && window.location.reload() );

	/*
	 * Bring the figures on screen up to date, without a reload.
	 *
	 * The page is asked for again, as it is, and only the parts marked with
	 * data-rvrt-region are swapped for their new selves: the totals, the
	 * sweep's status, the log, the widget's views. It is the same request a
	 * reload makes, so it shows exactly what this user may see here and
	 * needs no endpoint of its own. The parts keep their own element, so a
	 * hidden tab panel stays hidden, and each tells the scripts that work on
	 * it, with an rvrt:refresh event, to take its new contents in hand.
	 *
	 * Nothing is fetched while the tab is in the background, and a burst of
	 * news makes one request. A part with the focus in it is left until the
	 * focus moves out, so nobody loses their place, in the chart say. When the
	 * page cannot be had, the notice that asks for a reload is shown instead.
	 */

	// The flags a save or a sweep leaves on the address, which would show
	// their notice again.
	const NOTICE_ARGS = [ 'rvrt-notice', 'rvrt-booked', 'rvrt-skipped', 'rvrt-revisions', 'rvrt-posts', 'rvrt-finished', 'settings-updated' ];

	let wanted = false;
	let quiet = true;
	let timer = 0;
	let controller = null;

	/** Present a count the way the site writes numbers, as the PHP side does. */
	const count = ( value ) => Number( value ).toLocaleString( document.documentElement.lang || undefined );

	/** The log's totals a page holds, for each view on it. */
	const figures = ( root ) =>
		new Map(
			[ ...root.querySelectorAll( '[data-rvrt-figures]' ) ].map( ( element ) => [
				element.dataset.rvrtFigures,
				{ element, revisions: Number( element.dataset.revisions ) || 0, posts: Number( element.dataset.posts ) || 0 },
			] )
		);

	/*
	 * What the update brought in, by the log: the view that grew most, which
	 * on a site's Dashboard with a Network tab is the network when the sweep
	 * ran there. The old totals are then the new ones, for the next update.
	 */
	const compare = ( before, after ) => {
		let change = { revisions: 0, posts: 0 };

		for ( const [ view, old ] of before ) {
			const next = after.get( view );

			if ( ! next ) {
				continue;
			}

			const revisions = next.revisions - old.revisions;

			if ( revisions > change.revisions ) {
				change = { revisions, posts: Math.max( 0, next.posts - old.posts ) };
			}

			old.element.dataset.revisions = String( next.revisions );
			old.element.dataset.posts = String( next.posts );
		}

		return change;
	};

	/*
	 * Say what changed, in a notice that adds up every update until it is
	 * dismissed. Without a log there is nothing to count, so it says that a
	 * sweep finished and no more.
	 */
	const tell = ( change ) => {
		const notice = document.querySelector( '.rvrt-updated' );

		if ( ! notice ) {
			return;
		}

		const revisions = ( Number( notice.dataset.revisions ) || 0 ) + change.revisions;
		const posts = ( Number( notice.dataset.posts ) || 0 ) + change.posts;

		notice.dataset.revisions = String( revisions );
		notice.dataset.posts = String( posts );

		const message =
			revisions > 0
				? sprintf(
					/* translators: 1: number of revisions, e.g. "3 revisions", 2: number of posts, e.g. "2 posts". */
					_x( '%1$s removed from %2$s by a sweep in another tab. The figures here are up to date again.', 'tab sync notice', 'revision-retention' ),
					/* translators: %s: number of revisions. */
					sprintf( _nx( '%s revision', '%s revisions', revisions, 'tab sync notice', 'revision-retention' ), count( revisions ) ),
					/* translators: %s: number of posts. */
					sprintf( _nx( '%s post', '%s posts', posts, 'tab sync notice', 'revision-retention' ), count( posts ) )
				)
				: _x( 'A sweep in another tab has finished. The figures here are up to date again.', 'tab sync notice', 'revision-retention' );

		notice.querySelector( 'p' ).textContent = message;
		notice.hidden = false;
		speak( message );
	};

	document.addEventListener( 'click', ( event ) => {
		const notice = event.target.closest?.( '.rvrt-updated .notice-dismiss' )?.closest( '.rvrt-updated' );

		if ( ! notice ) {
			return;
		}

		notice.hidden = true;
		delete notice.dataset.revisions;
		delete notice.dataset.posts;
	} );

	const fallBack = () => {
		for ( const notice of document.querySelectorAll( '.rvrt-stale' ) ) {
			reveal( notice );
		}
	};

	const load = async () => {
		const regions = [ ...document.querySelectorAll( '[data-rvrt-region]' ) ];
		const focused = regions.find( ( region ) => region.contains( document.activeElement ) );

		if ( ! regions.length ) {
			wanted = false;

			return;
		}

		if ( focused ) {
			// Tried again once the focus has gone; a move within the part,
			// from one bar to the next, finds it still there and waits again.
			focused.addEventListener( 'focusout', () => window.setTimeout( schedule ), { once: true } );

			return;
		}

		controller?.abort();
		controller = new AbortController();

		const { signal } = controller;
		const url = new URL( window.location.href );

		for ( const name of NOTICE_ARGS ) {
			url.searchParams.delete( name );
		}

		let fresh;

		try {
			const response = await fetch( url, { credentials: 'same-origin', signal } );

			if ( ! response.ok ) {
				throw new Error( String( response.status ) );
			}

			fresh = new DOMParser().parseFromString( await response.text(), 'text/html' );
		} catch {
			// Only our own abort, for a newer request, is not a failure.
			if ( ! signal.aborted ) {
				wanted = false;
				fallBack();
			}

			return;
		}

		wanted = false;

		const before = figures( document );
		let swapped = 0;

		for ( const region of regions ) {
			const next = fresh.querySelector( `[data-rvrt-region="${ CSS.escape( region.dataset.rvrtRegion ) }"]` );

			if ( ! next ) {
				continue;
			}

			region.replaceChildren( ...[ ...next.childNodes ].map( ( node ) => document.importNode( node, true ) ) );
			region.dispatchEvent( new CustomEvent( 'rvrt:refresh', { bubbles: true } ) );
			swapped += 1;
		}

		// A page without the parts, the login screen after the session ran
		// out say, is no update at all.
		if ( ! swapped ) {
			fallBack();

			return;
		}

		for ( const notice of document.querySelectorAll( '.rvrt-stale' ) ) {
			notice.hidden = true;
		}

		// Compared even for this tab's own sweep, so the next update counts
		// only what came after it.
		const change = compare( before, figures( fresh ) );

		if ( ! quiet ) {
			tell( change );
		}

		quiet = true;
	};

	// Defined after load(), which only calls it once the page is running.
	const schedule = () => {
		window.clearTimeout( timer );

		if ( wanted && ! document.hidden ) {
			timer = window.setTimeout( load, 300 );
		}
	};

	document.addEventListener( 'visibilitychange', schedule );

	/**
	 * Ask for the figures to be brought up to date.
	 *
	 * @param {Object}  [options]
	 * @param {boolean} [options.announce] Say so once it is done: for news from another tab, not for this tab's own sweep, whose result is already said.
	 */
	const refresh = ( { announce = false } = {} ) => {
		wanted = true;
		quiet = quiet && ! announce;
		schedule();
	};

	/*
	 * A screen names the channels it listens on in data-channels: its own
	 * site's or the network's, or both on a Dashboard with a Network tab. A
	 * sweep that ends there having removed something brings its figures up to
	 * date; a preview deletes nothing and is let pass.
	 */
	document.addEventListener( 'DOMContentLoaded', () => {
		const hear = ( message ) => message.type === 'sweep:end' && message.mode === 'run' && refresh( { announce: true } );

		for ( const screen of document.querySelectorAll( '[data-channels]' ) ) {
			for ( const name of screen.dataset.channels.split( ' ' ).filter( Boolean ) ) {
				open( name, hear );
			}
		}
	} );

	window.rvrtChannel = { open, speak, reveal, refresh };
} )();
