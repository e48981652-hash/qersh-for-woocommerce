/**
 * Qersh for WooCommerce: the transfer box on the order-received page (docs/17 §4.10, §6.2).
 *
 * - Live status: POST qersh/v1/order-status every 15 s for the first 20 minutes after the order was placed, then
 *   every 60 s until the deadline plus the grace window, and a while after it (cfg.stop_at), so the page sees the
 *   expiry that runs then; paused while the page is hidden (the customer is in the wallet app), polled at once on
 *   return (once more even after the polling ended), stopped at a final state. When REST answers with something
 *   that is not JSON, or with 401/403, the page switches to ?wc-api=qersh&qo=status|txn and keeps it.
 * - The box follows the status as the server draws it (Checkout::box()): the transfer lines (amount, number,
 *   deadline, instructions) only while waiting or partial, the transaction-id section unless the order is paid,
 *   cancelled or under review. A change to partial reloads the page once, so the server shows the amount that
 *   remains; when it cannot, the amount line gives way to a line that says to reload. Once the polling ended with
 *   the order still waiting after the window, the box says the window has ended.
 * - The deadline is drawn in Cairo time with Intl.DateTimeFormat (timeZone Africa/Cairo, Latin digits), isolated by
 *   its first strong letter when the page's language writes it with Arabic words, else left to right. The server's
 *   "(within 2 hours)" is dropped once that duration would read differently.
 * - Copy buttons copy the raw value of their data- attribute; "Copied" is also said in a polite live region.
 * - The transaction-id form (Orange Cash): read with the same normaliser as the server, sent as the id alone. When
 *   what the field holds is not the id itself (a pasted message, spaces, Arabic-Indic digits), the id that will be
 *   sent shows under the field before sending ("We will send: …", docs/17 §14.1). A refused text that holds a number
 *   one or two digits longer than the id is still sent, that number alone, so the server counts the refusal (the
 *   transaction-id drift alert, docs/17 §4.9); the page shows the server's answer.
 * Plain ES5, no build step.
 */
( function ( win, doc ) {
	'use strict';

	var N = win.qershWcNormalize;
	var box = doc.querySelector( '[data-qersh-box]' );
	if ( ! box || ! N ) {
		return;
	}

	var cfg;
	try {
		cfg = JSON.parse( box.getAttribute( 'data-qersh' ) || '{}' );
	} catch ( e ) {
		return;
	}
	if ( ! cfg || ! cfg.order_id ) {
		return;
	}

	var strings = cfg.strings || {};
	var lines = cfg.lines || {};
	var offset = ( cfg.now || 0 ) - Math.floor( Date.now() / 1000 );
	var status = cfg.status || 'waiting';
	var claim = !! cfg.claim;
	var stopped = false;
	var inflight = false;
	var timer = null;
	var nudgeTimer = null;
	// Polling stops at these. `expired` and `review` are not final: a late transfer or the merchant can still
	// confirm the order.
	var FINAL = { paid: true, cancelled: true };
	// The statuses whose box shows the transfer lines (Checkout::box() 'pending').
	var PENDING = { waiting: true, partial: true };
	var ended = false;
	var amountStale = false;
	var savedTxn = '';
	var STORE_KEY = 'qersh_wc_fallback';
	var RELOAD_KEY = 'qersh_wc_reloaded_';
	var PARAGRAPH = String.fromCharCode( 0x2029 );
	var useFallback = false;

	try {
		useFallback = win.sessionStorage.getItem( STORE_KEY ) === '1';
	} catch ( e ) {
		useFallback = false;
	}

	function now() {
		return Math.floor( Date.now() / 1000 ) + offset;
	}

	function each( list, fn ) {
		for ( var i = 0; i < list.length; i++ ) {
			fn( list[ i ] );
		}
	}

	function rememberFallback() {
		useFallback = true;
		try {
			win.sessionStorage.setItem( STORE_KEY, '1' );
		} catch ( e ) {
			// Private mode: remembered for this page only.
		}
	}

	// POST JSON; switches to the fallback address once when REST is blocked.
	function post( kind, body ) {
		var url = useFallback ? cfg.urls[ kind + '_fallback' ] : cfg.urls[ kind ];
		var wasFallback = useFallback;
		return win.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			var type = res.headers.get( 'content-type' ) || '';
			if ( ! wasFallback && ( res.status === 401 || res.status === 403 || type.indexOf( 'json' ) === -1 ) ) {
				throw { fallback: true };
			}
			return res.json().then( function ( json ) {
				if ( ! wasFallback && res.status === 404 && json && json.code === 'rest_no_route' ) {
					throw { fallback: true };
				}
				return { http: res.status, json: json || {}, retry: parseInt( res.headers.get( 'Retry-After' ) || '0', 10 ) || 0 };
			}, function () {
				if ( ! wasFallback ) {
					throw { fallback: true };
				}
				return { http: res.status, json: {}, retry: 0 };
			} );
		} ).catch( function ( err ) {
			if ( err && err.fallback && ! wasFallback && cfg.urls[ kind + '_fallback' ] ) {
				rememberFallback();
				return post( kind, body );
			}
			throw err;
		} );
	}

	// The status the box shows: the server's, except that a page whose polling ended with the order still waiting
	// after the transfer window (the expiry has not run yet) says the window has ended.
	function shown() {
		return ended && PENDING[ status ] ? 'expired' : status;
	}

	function lineKey() {
		var s = shown();
		if ( s === 'waiting' ) {
			if ( claim ) {
				return 'saved';
			}
			return cfg.decision ? 'waiting_decision' : 'waiting';
		}
		return lines[ s ] ? s : 'waiting';
	}

	// After a change to partial without a reload, the amount of the page (the whole order) is no longer what to
	// send: the line and its copy button give way to "Reload this page…".
	function renderAmount() {
		if ( ! amountStale ) {
			return;
		}
		each( box.querySelectorAll( '[data-qersh-amount]' ), function ( node ) {
			if ( node.hidden || ! node.parentNode ) {
				return;
			}
			node.hidden = true;
			var note = doc.createElement( 'li' );
			note.className = 'qersh-line qersh-line--reload';
			note.textContent = strings.reload_rest || '';
			node.parentNode.insertBefore( note, node.nextSibling );
		} );
	}

	function renderStatus() {
		var s = shown();
		var el = box.querySelector( '[data-qersh-status]' );
		if ( el ) {
			el.textContent = lines[ lineKey() ] || '';
			el.className = 'qersh-status qersh-status--' + s;
			if ( s === 'paid' ) {
				var mark = doc.createElement( 'span' );
				mark.className = 'qersh-status__mark';
				mark.setAttribute( 'aria-hidden', 'true' );
				mark.textContent = ' ' + String.fromCharCode( 0x2713 );
				el.appendChild( mark );
			}
		}
		// As the server draws the box: the transfer lines only while waiting or partial; the transaction-id section
		// by Claims::accepts() (an Orange order our expiry cancelled still takes an id, one under review does not).
		each( box.querySelectorAll( '[data-qersh-pending]' ), function ( node ) {
			node.hidden = ! PENDING[ s ];
		} );
		each( box.querySelectorAll( '[data-qersh-txn]' ), function ( node ) {
			node.hidden = !! FINAL[ s ] || s === 'review';
		} );
		renderAmount();
	}

	function renderNudge() {
		var el = box.querySelector( '[data-qersh-nudge]' );
		if ( ! el ) {
			return;
		}
		// Checkout::nudge_key(): the Orange reminder replaces the base line only while the field is there and no id
		// was entered (it points at the field "above").
		var waiting = shown() === 'waiting';
		var text = '';
		if ( waiting && now() >= cfg.nudge_at ) {
			if ( cfg.orange && cfg.txn_open ) {
				text = claim ? '' : strings.nudge_txn || '';
			} else {
				text = strings.nudge_other || '';
			}
		}
		el.textContent = text;
		el.hidden = text === '';
		if ( waiting && now() < cfg.nudge_at && ! nudgeTimer ) {
			nudgeTimer = win.setTimeout( function () {
				nudgeTimer = null;
				renderNudge();
			}, Math.max( 1, cfg.nudge_at - now() ) * 1000 );
		}
	}

	// Whether the customer has an unsent transaction id in the field (a reload would lose it).
	function typingTxn() {
		var input = doc.getElementById( 'qersh_txn' );
		if ( ! input ) {
			return false;
		}
		return doc.activeElement === input || ( input.value !== '' && input.value !== savedTxn );
	}

	// Reloads the page once for this order, so the server draws the amount that remains. Not while the customer
	// types a transaction id, and never twice (a page cache serving the older page would loop).
	function reloadForRest() {
		if ( typingTxn() || ! win.location || typeof win.location.reload !== 'function' ) {
			return false;
		}
		var key = RELOAD_KEY + cfg.order_id;
		try {
			if ( win.sessionStorage.getItem( key ) === 'partial' ) {
				return false;
			}
			win.sessionStorage.setItem( key, 'partial' );
		} catch ( e ) {
			return false;
		}
		stop();
		win.location.reload();
		return true;
	}

	function update( next, nextClaim ) {
		// Only a real change is drawn: rewriting the live region with the same text makes screen readers say it again.
		var claimChanged = typeof nextClaim === 'boolean' && nextClaim !== claim;
		if ( claimChanged ) {
			claim = nextClaim;
		}
		if ( next !== status || claimChanged ) {
			var toPartial = next === 'partial' && status !== 'partial';
			status = next;
			if ( toPartial ) {
				if ( reloadForRest() ) {
					return;
				}
				amountStale = true;
			}
			renderStatus();
			renderNudge();
		}
		if ( FINAL[ status ] ) {
			stop();
		}
	}

	// The polling ended: a page still waiting after the transfer window says the window has ended.
	function windowEnded() {
		if ( ended || ! PENDING[ status ] || ! cfg.window_end || now() <= cfg.window_end ) {
			return;
		}
		ended = true;
		renderStatus();
		renderNudge();
	}

	function stop() {
		stopped = true;
		if ( timer ) {
			win.clearTimeout( timer );
			timer = null;
		}
	}

	function delay() {
		return now() - cfg.placed_at < cfg.fast_for ? cfg.fast : cfg.slow;
	}

	function schedule() {
		if ( timer ) {
			win.clearTimeout( timer );
			timer = null;
		}
		if ( stopped || doc.hidden ) {
			return;
		}
		if ( now() > cfg.stop_at ) {
			stop();
			windowEnded();
			return;
		}
		timer = win.setTimeout( poll, delay() * 1000 );
	}

	function poll() {
		timer = null;
		if ( stopped || inflight || ! win.fetch ) {
			return;
		}
		inflight = true;
		post( 'status', { order_id: cfg.order_id, key: cfg.key } ).then( function ( r ) {
			inflight = false;
			if ( r.http === 200 && r.json && typeof r.json.status === 'string' ) {
				update( r.json.status, r.json.claim );
			} else if ( r.http === 404 ) {
				stop();
				return;
			}
			schedule();
		}, function () {
			inflight = false;
			schedule();
		} );
	}

	function renderTimes() {
		if ( ! win.Intl || ! win.Intl.DateTimeFormat ) {
			return;
		}
		var lang = doc.documentElement.getAttribute( 'lang' ) || 'en';
		each( box.querySelectorAll( '[data-qersh-time]' ), function ( el ) {
			var ts = parseInt( el.getAttribute( 'data-qersh-time' ), 10 );
			if ( ! ts ) {
				return;
			}
			try {
				var day = new win.Intl.DateTimeFormat( 'en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' } );
				var sameDay = day.format( new Date( ts * 1000 ) ) === day.format( new Date( now() * 1000 ) );
				var options = { timeZone: 'Africa/Cairo', hour: 'numeric', minute: '2-digit', numberingSystem: 'latn' };
				if ( ! sameDay ) {
					options.day = 'numeric';
					options.month = 'long';
				}
				var text = new win.Intl.DateTimeFormat( lang, options ).format( new Date( ts * 1000 ) );
				el.textContent = text;
				// Format::isolate(): a time written with Arabic words ("4:35 م") takes its direction from its first
				// strong letter; one without them stays left to right.
				el.setAttribute( 'dir', N.hasRtl( text ) ? 'auto' : 'ltr' );
			} catch ( e ) {
				// Keep the server's text.
			}
		} );
	}

	// human_time_diff()'s unit and rounded count, so the page knows when the server's "(within …)" reads wrong.
	function span( diff ) {
		var units = [ [ 60, 1, 's' ], [ 3600, 60, 'm' ], [ 86400, 3600, 'h' ], [ 604800, 86400, 'd' ], [ 2592000, 604800, 'w' ], [ 31536000, 2592000, 'mo' ] ];
		if ( diff <= 0 ) {
			return 'past';
		}
		for ( var i = 0; i < units.length; i++ ) {
			if ( diff < units[ i ][ 0 ] ) {
				return units[ i ][ 2 ] + Math.max( 1, Math.round( diff / units[ i ][ 1 ] ) );
			}
		}
		return 'y' + Math.max( 1, Math.round( diff / 31536000 ) );
	}

	// "Before: 4:35 PM Cairo time (within 2 hours)" becomes "Before: 4:35 PM Cairo time" (strings.before), keeping
	// the time element, once the duration has moved on or the deadline has passed.
	function renderLeft() {
		var line = box.querySelector( '[data-qersh-left]' );
		if ( ! line || ! cfg.deadline || span( cfg.deadline - now() ) === span( cfg.deadline - ( cfg.now || 0 ) ) ) {
			return;
		}
		var template = strings.before || '';
		var at = template.indexOf( '%s' );
		var time = line.querySelector( '[data-qersh-time]' );
		var keep = time && time.parentNode && time.parentNode !== line ? time.parentNode : time;
		if ( at === -1 || ! keep ) {
			return;
		}
		line.textContent = '';
		line.appendChild( doc.createTextNode( template.slice( 0, at ) ) );
		line.appendChild( keep );
		line.appendChild( doc.createTextNode( template.slice( at + 2 ) ) );
		line.removeAttribute( 'data-qersh-left' );
	}

	function tick() {
		renderTimes();
		renderLeft();
		win.setTimeout( tick, 30000 );
	}

	function copy( button ) {
		var value = button.getAttribute( 'data-qersh-copy' ) || '';
		var said = box.querySelector( '[data-qersh-copy-msg]' );
		var done = function () {
			button.textContent = strings.copied || button.textContent;
			// The button's name is its aria-label, so the text swap is not heard: say it in the live region, emptied
			// first so a second copy is said again.
			if ( said ) {
				said.textContent = '';
				win.setTimeout( function () {
					said.textContent = strings.copied || '';
				}, 50 );
			}
			win.setTimeout( function () {
				button.textContent = strings.copy || button.textContent;
				if ( said ) {
					said.textContent = '';
				}
			}, 1500 );
		};
		var fallback = function () {
			var area = doc.createElement( 'textarea' );
			area.value = value;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			doc.body.appendChild( area );
			area.select();
			try {
				if ( doc.execCommand( 'copy' ) ) {
					done();
				}
			} catch ( e ) {
				// Nothing to do: the value stays visible next to the button.
			}
			doc.body.removeChild( area );
		};
		if ( win.navigator.clipboard && win.isSecureContext ) {
			win.navigator.clipboard.writeText( value ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function txnForm() {
		var form = box.querySelector( '[data-qersh-txn-form]' );
		var input = doc.getElementById( 'qersh_txn' );
		if ( ! form || ! input ) {
			return;
		}
		var button = form.querySelector( '[data-qersh-txn-send]' );
		var msg = form.querySelector( '[data-qersh-txn-msg]' );
		var errors = strings.txn_errors || {};
		form.hidden = false;

		function say( text, isError ) {
			if ( ! msg ) {
				return;
			}
			msg.textContent = text || '';
			msg.setAttribute( 'role', isError ? 'alert' : 'status' );
			msg.className = 'qersh-txn__msg' + ( isError ? ' qersh-txn__msg--error' : '' );
			if ( isError ) {
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				input.removeAttribute( 'aria-invalid' );
			}
		}

		function errorText( code ) {
			return errors[ code ] || errors.other || '';
		}

		function read() {
			var txn = cfg.txn || {};
			return N.canonicalTyped( txn.provider, input.value, txn.payer_key || null, txn.store_numbers || [], txn.shapes );
		}

		// After a refusal of what was read: the number one or two digits longer than the id, if the field holds one.
		function longRun( res ) {
			var txn = cfg.txn || {};
			if ( res.error !== 'qersh_txn_format' && res.error !== 'qersh_txn_wallet_number' ) {
				return null;
			}
			return N.longRun ? N.longRun( txn.provider, input.value, txn.shapes ) : null;
		}

		// "We will send: <id>", the id isolated left to right, when the field holds more than the id itself (a pasted
		// message, spaces, Arabic-Indic digits). Nothing while it is the id as typed, or not readable yet: the errors
		// wait for the send, so a customer typing digits is not told off at each key.
		function preview() {
			var res = read();
			var template = strings.will_send || '';
			var at = template.indexOf( '%s' );
			say( '', false );
			if ( ! msg || ! res.ok || at === -1 || res.txn === input.value.replace( /^\s+|\s+$/g, '' ) ) {
				return;
			}
			var id = doc.createElement( 'bdi' );
			id.setAttribute( 'dir', 'ltr' );
			id.textContent = res.txn;
			msg.appendChild( doc.createTextNode( template.slice( 0, at ) ) );
			msg.appendChild( id );
			msg.appendChild( doc.createTextNode( template.slice( at + 2 ) ) );
		}

		// An input of type text drops line breaks on paste; a pasted message keeps them as paragraph separators,
		// which the normaliser reads as line breaks, so two numbers on two lines are never joined.
		input.addEventListener( 'paste', function ( e ) {
			var text = e.clipboardData && e.clipboardData.getData ? e.clipboardData.getData( 'text' ) : '';
			if ( ! text || ! /[\r\n]/.test( text ) ) {
				return;
			}
			e.preventDefault();
			text = text.replace( /\r\n|\r|\n/g, PARAGRAPH );
			var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
			var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : input.value.length;
			input.value = input.value.slice( 0, start ) + text + input.value.slice( end );
			var caret = start + text.length;
			try {
				input.setSelectionRange( caret, caret );
			} catch ( err ) {
				// Some input types do not support a selection.
			}
			// Setting the value fires no input event.
			preview();
		} );

		input.addEventListener( 'input', preview );

		function send() {
			var res = read();
			// A refused id one or two digits longer than the shape still goes to the server, alone (nothing else of
			// what the customer pasted): its refusals tell the store that the operator lengthened its ids
			// (Diagnostics::record_txn_refusal(), docs/17 §4.9). The server answers with the refusal.
			var value = res.ok ? res.txn : longRun( res );
			// What the page says when the server cannot be asked, or answers without a code.
			var fallback = res.ok ? 'other' : res.error;
			if ( value === null || ! win.fetch ) {
				say( errorText( fallback ), true );
				return;
			}
			if ( button ) {
				button.disabled = true;
			}
			say( strings.sending || '', false );
			post( 'txn', { order_id: cfg.order_id, key: cfg.key, txn: value } ).then( function ( r ) {
				if ( button ) {
					button.disabled = false;
				}
				if ( r.http === 200 && r.json && r.json.status === 'saved' ) {
					input.value = typeof r.json.txn === 'string' ? r.json.txn : value;
					savedTxn = input.value;
					claim = true;
					say( status === 'waiting' ? '' : strings.txn_saved || '', false );
					renderStatus();
					renderNudge();
					return;
				}
				say( errorText( ( r.json && r.json.code ) || fallback ), true );
			}, function () {
				if ( button ) {
					button.disabled = false;
				}
				say( errorText( fallback ), true );
			} );
		}

		if ( button ) {
			button.addEventListener( 'click', send );
		}
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				send();
			}
		} );
	}

	each( box.querySelectorAll( '[data-qersh-copy]' ), function ( button ) {
		button.hidden = false;
		button.addEventListener( 'click', function () {
			copy( button );
		} );
	} );

	tick();
	renderNudge();
	txnForm();

	doc.addEventListener( 'visibilitychange', function () {
		if ( doc.hidden ) {
			if ( timer ) {
				win.clearTimeout( timer );
				timer = null;
			}
		} else if ( ! FINAL[ status ] ) {
			// Back from the wallet app: one look even when the polling ended (schedule() stops again after it).
			stopped = false;
			renderLeft();
			poll();
		}
	} );

	if ( FINAL[ status ] ) {
		stop();
	} else {
		schedule();
	}
}( window, document ) );
