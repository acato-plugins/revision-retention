/**
 * The card beside the Settings menu that leads to the walkthrough.
 *
 * Plain JavaScript without jQuery, as it loads on every admin screen until
 * the walkthrough is taken. Styled by pointer.css; the data comes from
 * Admin\Tour::menu_pointer() in window.rvrtMenuPointer.
 */
( () => {
	'use strict';

	const data = window.rvrtMenuPointer;
	const target = document.getElementById( 'menu-settings' );

	if ( ! data || ! target ) {
		return;
	}

	const rtl = document.documentElement.dir === 'rtl';

	const el = ( tag, className, text ) => {
		const node = document.createElement( tag );

		node.className = className;

		if ( text ) {
			node.textContent = text;
		}

		return node;
	};

	const box = el( 'div', 'rvrt-menu-pointer' );
	const body = el( 'div', 'rvrt-menu-pointer-body' );
	const head = el( 'div', 'rvrt-menu-pointer-head' );
	const wapuu = el( 'img', 'rvrt-menu-pointer-wapuu' );
	const title = el( 'h2', 'rvrt-menu-pointer-title', data.title );
	const text = el( 'p', 'rvrt-menu-pointer-text', data.text );
	const actions = el( 'div', 'rvrt-menu-pointer-actions' );
	const go = el( 'a', 'rvrt-menu-pointer-go', data.linkText );
	const dismiss = el( 'button', 'rvrt-menu-pointer-dismiss', data.dismiss );

	title.id = 'rvrt-menu-pointer-title';
	text.id = 'rvrt-menu-pointer-text';
	box.setAttribute( 'role', 'dialog' );
	box.setAttribute( 'aria-labelledby', title.id );
	box.setAttribute( 'aria-describedby', text.id );
	wapuu.src = data.wapuu;
	wapuu.alt = '';
	wapuu.width = 44;
	wapuu.height = 44;
	go.href = data.link;
	dismiss.type = 'button';

	head.append( wapuu, title );
	actions.append( go, dismiss );
	body.append( head, text, actions );
	box.append( body );

	// The admin menu is pinned when it fits the window and scrolls with the
	// page when it does not. The card does what the menu does, so it moves
	// with the Settings item by itself, without a script on every scroll:
	// fixed beside a pinned menu, in page coordinates beside one that scrolls.
	// It opens whole inside the window, under the admin bar, and never
	// scrolls the page itself; the arrow slides along its edge to keep
	// pointing at the item.
	const menu = document.getElementById( 'adminmenuwrap' );

	const place = () => {
		const pinned = !! menu && window.getComputedStyle( menu ).position === 'fixed';
		const offset = pinned ? 0 : window.scrollY;
		const rect = target.getBoundingClientRect();
		const middle = rect.top + rect.height / 2;
		const height = box.offsetHeight;
		const lowest = Math.max( 40, window.innerHeight - height - 16 );
		const top = Math.min( Math.max( 40, middle - 39 ), lowest );

		box.style.position = pinned ? 'fixed' : 'absolute';
		box.style.top = `${ top + offset }px`;
		box.style.setProperty( '--rvrt-arrow', `${ Math.min( Math.max( middle - top - 7, 12 ), height - 26 ) }px` );

		if ( rtl ) {
			box.style.right = `${ document.documentElement.clientWidth - rect.left + 12 }px`;
		} else {
			box.style.left = `${ rect.right + window.scrollX * ( pinned ? 0 : 1 ) + 12 }px`;
		}
	};

	const onKey = ( event ) => {
		if ( event.key === 'Escape' && box.contains( document.activeElement ) ) {
			close();
		}
	};

	// "Not now" is core's own dismissal of a pointer, per user and per
	// walkthrough version, so a newer walkthrough brings the card back.
	const close = () => {
		box.remove();
		window.removeEventListener( 'resize', place );
		document.removeEventListener( 'keydown', onKey );

		const form = new FormData();

		form.append( 'action', 'dismiss-wp-pointer' );
		form.append( 'pointer', data.name );
		window.fetch( data.ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' } ).catch( () => {} );
	};

	dismiss.addEventListener( 'click', close );

	// Other plugins point at the admin menu too: wait until theirs are closed,
	// rather than stack on top of them.
	const open = () => {
		const others = [ ...document.querySelectorAll( '.wp-pointer' ) ].some( ( node ) => node.getClientRects().length > 0 );

		if ( others ) {
			window.setTimeout( open, 1000 );

			return;
		}

		document.body.append( box );
		place();

		// WordPress pins and unpins the menu as the window is resized.
		window.addEventListener( 'resize', place );
		document.addEventListener( 'keydown', onKey );
	};

	window.setTimeout( open, 500 );
} )();
