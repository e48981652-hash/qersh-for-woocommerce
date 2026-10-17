/**
 * Qersh for WooCommerce: the transfer box on the order-received page (docs/17 §4.10, §6.2).
 *
 * - Live status: POST qersh/v1/order-status every 15 s for the first 20 minutes after the order was placed, then
 *   every 60 s until the deadline plus the grace window; paused while the page is hidden (the customer is in the
 *   wallet app), polled at once on return, stopped at a final state. When REST answers with something that is not
 *   JSON, or with 401/403, the page switches to ?wc-api=qersh&qo=status|txn and keeps it.
 * - The deadline is drawn in Cairo time with Intl.DateTimeFormat (timeZone Africa/Cairo, Latin digits).
 * - Copy buttons copy the raw value of their data- attribute.
 * - The transaction-id form (Orange Cash): read with the same normaliser as the server, sent as the id alone.
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
	var FINAL = { paid: true, cancelled: true };
	var STORE_KEY = 'qersh_wc_fallback';
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

	function lineKey() {
		if ( status === 'waiting' ) {
			if ( claim ) {
				return 'saved';
			}
			return cfg.decision ? 'waiting_decision' : 'waiting';
		}
		return lines[ status ] ? status : 'waiting';
	}

	function renderStatus() {
		var el = box.querySelector( '[data-qersh-status]' );
		if ( ! el ) {
			return;
		}
		el.textContent = lines[ lineKey() ] || '';
		el.className = 'qersh-status qersh-status--' + status;
		if ( status === 'paid' ) {
			var mark = doc.createElement( 'span' );
			mark.className = 'qersh-status__mark';
			mark.setAttribute( 'aria-hidden', 'true' );
			mark.textContent = ' ' + String.fromCharCode( 0x2713 );
			el.appendChild( mark );
		}
		if ( FINAL[ status ] ) {
			each( box.querySelectorAll( '[data-qersh-pending], [data-qersh-txn]' ), function ( node ) {
				node.hidden = true;
			} );
		}
	}

	function renderNudge() {
		var el = box.querySelector( '[data-qersh-nudge]' );
		if ( ! el ) {
			return;
		}
		// Checkout::nudge_key(): the Orange reminder replaces the base line only while the field is there and no id
		// was entered (it points at the field "above").
		var text = '';
		if ( status === 'waiting' && now() >= cfg.nudge_at ) {
			if ( cfg.orange && cfg.txn_open ) {
				text = claim ? '' : strings.nudge_txn || '';
			} else {
				text = strings.nudge_other || '';
			}
		}
		el.textContent = text;
		el.hidden = text === '';
		if ( status === 'waiting' && now() < cfg.nudge_at && ! nudgeTimer ) {
			nudgeTimer = win.setTimeout( function () {
				nudgeTimer = null;
				renderNudge();
			}, Math.max( 1, cfg.nudge_at - now() ) * 1000 );
		}
	}

	function update( next, nextClaim ) {
		if ( typeof nextClaim === 'boolean' ) {
			claim = nextClaim;
		}
		if ( next !== status || typeof nextClaim === 'boolean' ) {
			status = next;
			renderStatus();
			renderNudge();
		}
		if ( FINAL[ status ] ) {
			stop();
		}
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
				el.textContent = new win.Intl.DateTimeFormat( lang, options ).format( new Date( ts * 1000 ) );
			} catch ( e ) {
				// Keep the server's text.
			}
		} );
	}

	function copy( button ) {
		var value = button.getAttribute( 'data-qersh-copy' ) || '';
		var done = function () {
			button.textContent = strings.copied || button.textContent;
			win.setTimeout( function () {
				button.textContent = strings.copy || button.textContent;
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
		} );

		input.addEventListener( 'input', function () {
			say( '', false );
		} );

		function send() {
			var txn = cfg.txn || {};
			var res = N.canonicalTyped( txn.provider, input.value, txn.payer_key || null, txn.store_numbers || [], txn.shapes );
			if ( ! res.ok ) {
				say( errorText( res.error ), true );
				return;
			}
			if ( ! win.fetch ) {
				say( errorText( 'other' ), true );
				return;
			}
			if ( button ) {
				button.disabled = true;
			}
			say( strings.sending || '', false );
			post( 'txn', { order_id: cfg.order_id, key: cfg.key, txn: res.txn } ).then( function ( r ) {
				if ( button ) {
					button.disabled = false;
				}
				if ( r.http === 200 && r.json && r.json.status === 'saved' ) {
					input.value = typeof r.json.txn === 'string' ? r.json.txn : res.txn;
					claim = true;
					say( status === 'waiting' ? '' : strings.txn_saved || '', false );
					renderStatus();
					renderNudge();
					return;
				}
				say( errorText( r.json && r.json.code ), true );
			}, function () {
				if ( button ) {
					button.disabled = false;
				}
				say( errorText( 'other' ), true );
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

	renderTimes();
	renderNudge();
	txnForm();

	doc.addEventListener( 'visibilitychange', function () {
		if ( doc.hidden ) {
			if ( timer ) {
				win.clearTimeout( timer );
				timer = null;
			}
		} else if ( ! stopped ) {
			poll();
		}
	} );

	if ( FINAL[ status ] ) {
		stop();
	} else {
		schedule();
	}
}( window, document ) );
