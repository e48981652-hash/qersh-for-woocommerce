/**
 * Qersh for WooCommerce: the WooCommerce → Qersh page.
 *
 * The link with the connection key (docs/17 §4.8 rules 5–7) lives only in a variable of this file: it comes from
 * one POST, is shown masked in a <code>, drawn as a QR code in a <canvas> on request, copied from the variable, and
 * opened with window.location.assign() from the variable. It never goes into an attribute, a text node, a
 * href, localStorage or sessionStorage, and everything is wiped on close, pagehide and a page restored from the
 * back-forward cache.
 */
( function () {
	'use strict';

	var config = window.qershWcAdmin || {};
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = function ( text ) {
		return text;
	};
	var _n = function ( single, plural, number ) {
		return 1 === number ? single : plural;
	};
	var sprintf = function ( format, value ) {
		return String( format ).replace( '%d', String( value ) );
	};
	if ( i18n && typeof i18n.__ === 'function' ) {
		__ = i18n.__;
	}
	if ( i18n && typeof i18n._n === 'function' ) {
		_n = i18n._n;
	}
	if ( i18n && typeof i18n.sprintf === 'function' ) {
		sprintf = i18n.sprintf;
	}

	/* ---------------------------------------------------------------------------------------------------------
	 * Small helpers.
	 * ------------------------------------------------------------------------------------------------------- */

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( name ) {
			node.setAttribute( name, attrs[ name ] );
		} );
		if ( typeof text === 'string' ) {
			node.textContent = text;
		}
		return node;
	}

	function post( url, fields ) {
		var body = new window.FormData();
		Object.keys( fields ).forEach( function ( name ) {
			body.append( name, fields[ name ] );
		} );
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			body: body,
		} );
	}

	// `secret` (the link with the connection key): only the Clipboard API, never the fallback, which puts the text
	// into a temporary <textarea> of the page (docs/17 §4.8 rule 5).
	function copyText( text, done, secret ) {
		if ( window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( text ).then(
				function () {
					done( true );
				},
				function () {
					done( secret ? false : copyFallback( text ) );
				}
			);
			return;
		}
		done( secret ? false : copyFallback( text ) );
	}

	function copyFallback( text ) {
		try {
			if ( window.ClipboardJS && typeof window.ClipboardJS.copy === 'function' ) {
				window.ClipboardJS.copy( text );
				return true;
			}
		} catch ( e ) {
			return false;
		}
		return false;
	}

	function drawQr( canvas, text ) {
		if ( typeof window.qrcode !== 'function' ) {
			return false;
		}
		var qr = window.qrcode( 0, 'M' );
		qr.addData( text );
		qr.make();
		var count = qr.getModuleCount();
		var quiet = 4;
		var scale = Math.max( 2, Math.floor( 264 / ( count + 2 * quiet ) ) );
		var size = ( count + 2 * quiet ) * scale;
		canvas.width = size;
		canvas.height = size;
		var ctx = canvas.getContext( '2d' );
		ctx.fillStyle = '#ffffff';
		ctx.fillRect( 0, 0, size, size );
		ctx.fillStyle = '#000000';
		for ( var r = 0; r < count; r++ ) {
			for ( var c = 0; c < count; c++ ) {
				if ( qr.isDark( r, c ) ) {
					ctx.fillRect( ( c + quiet ) * scale, ( r + quiet ) * scale, scale, scale );
				}
			}
		}
		return true;
	}

	function clearCanvas( canvas ) {
		if ( ! canvas ) {
			return;
		}
		var ctx = canvas.getContext( '2d' );
		if ( ctx ) {
			ctx.clearRect( 0, 0, canvas.width, canvas.height );
		}
		canvas.width = 0;
		canvas.height = 0;
		canvas.hidden = true;
	}

	/* ---------------------------------------------------------------------------------------------------------
	 * Copy buttons and QR codes of links without a key.
	 * ------------------------------------------------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.qersh-wc-copy' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var text = button.getAttribute( 'data-clipboard-text' ) || '';
		var label = button.textContent;
		copyText( text, function ( ok ) {
			button.textContent = ok ? __( 'Copied', 'qersh-for-woocommerce' ) : __( 'Copy failed', 'qersh-for-woocommerce' );
			window.setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.qersh-wc-qr-toggle' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var canvas = document.getElementById( button.getAttribute( 'aria-controls' ) || '' );
		if ( ! canvas ) {
			return;
		}
		if ( canvas.hidden ) {
			if ( drawQr( canvas, canvas.getAttribute( 'data-qersh-qr' ) || '' ) ) {
				canvas.hidden = false;
				button.setAttribute( 'aria-expanded', 'true' );
				button.textContent = __( 'Hide QR code', 'qersh-for-woocommerce' );
			}
		} else {
			clearCanvas( canvas );
			button.setAttribute( 'aria-expanded', 'false' );
			button.textContent = __( 'Show QR code', 'qersh-for-woocommerce' );
		}
	} );

	/* ---------------------------------------------------------------------------------------------------------
	 * Paste box: a write-only password field (docs/17 §10 #6). A password field drops the line breaks of a
	 * pasted share message, so a multi-line paste is kept in a variable of this file instead, the field shows
	 * only how long it is, and the text goes into the form when it is sent. Confirm before a paste cancels a
	 * pending link.
	 * ------------------------------------------------------------------------------------------------------- */

	var pasted = {};

	function isSecretField( node ) {
		return !! ( node && node.classList && node.classList.contains( 'qersh-wc-secret-field' ) && node.id );
	}

	function pasteStatus( field, text ) {
		var form = field.form;
		var status = form ? form.querySelector( '.qersh-wc-paste-status' ) : null;
		if ( ! status ) {
			return;
		}
		status.textContent = text;
		status.hidden = '' === text;
	}

	function forgetPaste( field ) {
		delete pasted[ field.id ];
		field.required = true;
		pasteStatus( field, '' );
	}

	document.addEventListener( 'paste', function ( event ) {
		var field = event.target;
		if ( ! isSecretField( field ) ) {
			return;
		}
		var data = event.clipboardData || window.clipboardData;
		var text = data && typeof data.getData === 'function' ? data.getData( 'text' ) : '';
		if ( typeof text !== 'string' || ! /[\r\n\u2028\u2029]/.test( text ) ) {
			return; // One line (the key, the link, the QERSH_WEBHOOK_SECRET line): the field takes it as it is.
		}
		event.preventDefault();
		pasted[ field.id ] = text;
		field.value = '';
		field.required = false;
		pasteStatus(
			field,
			/* translators: %d: number of characters. */
			sprintf( _n( 'Message pasted (%d character).', 'Message pasted (%d characters).', text.length, 'qersh-for-woocommerce' ), text.length )
		);
	} );

	document.addEventListener( 'input', function ( event ) {
		var field = event.target;
		if ( isSecretField( field ) && Object.prototype.hasOwnProperty.call( pasted, field.id ) ) {
			forgetPaste( field ); // Typing replaces the pasted message.
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'qersh-wc-paste' ) ) {
			return;
		}
		if ( '1' === form.getAttribute( 'data-qersh-pending' ) ) {
			var box = form.querySelector( '.qersh-wc-confirm-replace' );
			if ( box && ! box.checked ) {
				if ( ! window.confirm( __( 'You have a link with the connection key that nothing has arrived with yet. Pasting this message cancels it and makes the pasted key the connection key. Continue?', 'qersh-for-woocommerce' ) ) ) {
					event.preventDefault();
					return;
				}
				box.checked = true;
			}
		}
		var field = form.querySelector( '.qersh-wc-secret-field' );
		if ( ! isSecretField( field ) || ! Object.prototype.hasOwnProperty.call( pasted, field.id ) ) {
			return;
		}
		var carrier = el( 'input', { type: 'hidden', class: 'qersh-wc-paste-carrier' } );
		carrier.name = 'qersh_key';
		carrier.value = pasted[ field.id ];
		delete pasted[ field.id ];
		field.removeAttribute( 'name' );
		form.appendChild( carrier );
	} );

	function wipePastes() {
		pasted = {};
		Array.prototype.forEach.call( document.querySelectorAll( '.qersh-wc-paste-carrier' ), function ( node ) {
			node.value = '';
			if ( node.parentNode ) {
				node.parentNode.removeChild( node );
			}
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.qersh-wc-secret-field' ), function ( field ) {
			field.value = '';
			field.setAttribute( 'name', 'qersh_key' );
			field.required = true;
			pasteStatus( field, '' );
		} );
	}

	/* ---------------------------------------------------------------------------------------------------------
	 * Dismissed notices are remembered.
	 * ------------------------------------------------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.qersh-wc-notice .notice-dismiss' ) : null;
		if ( ! button || ! config.ajaxUrl ) {
			return;
		}
		var notice = button.closest( '.qersh-wc-notice' );
		var id = notice ? notice.getAttribute( 'data-qersh-notice' ) : '';
		if ( ! id ) {
			return;
		}
		post( config.ajaxUrl, {
			action: 'qersh_wc_notice_dismiss',
			nonce: config.noticeNonce || '',
			notice: id,
		} ).catch( function () {} );
	} );

	/* ---------------------------------------------------------------------------------------------------------
	 * "Generate your developer's link with the connection key".
	 * ------------------------------------------------------------------------------------------------------- */

	var secretLink = null;
	var linkMeta = null;
	var modal = null;
	var qrTimer = null;

	function wipe() {
		secretLink = null;
		linkMeta = null;
		if ( qrTimer ) {
			window.clearTimeout( qrTimer );
			qrTimer = null;
		}
		if ( modal ) {
			clearCanvas( modal.querySelector( 'canvas' ) );
			var code = modal.querySelector( 'code' );
			if ( code ) {
				code.textContent = '';
			}
			if ( modal.parentNode ) {
				modal.parentNode.removeChild( modal );
			}
			modal = null;
			document.removeEventListener( 'keydown', onKeydown, true );
			document.body.classList.remove( 'qersh-wc-modal-open' );
		}
	}

	function masked( link ) {
		return link.replace( /([?&]secret=)[^&]*/, '$1qsk_••••••' );
	}

	function focusables() {
		return modal ? Array.prototype.slice.call( modal.querySelectorAll( 'button:not([hidden]):not([disabled])' ) ).filter( function ( b ) {
			return ! b.closest( '[hidden]' );
		} ) : [];
	}

	function onKeydown( event ) {
		if ( ! modal ) {
			return;
		}
		if ( 'Escape' === event.key || 'Esc' === event.key ) {
			event.preventDefault(); // Escape does not close this window (docs/17 §4.8 rule 7).
			event.stopPropagation();
			return;
		}
		if ( 'Tab' === event.key ) {
			var list = focusables();
			if ( ! list.length ) {
				return;
			}
			var first = list[ 0 ];
			var last = list[ list.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			} else if ( ! modal.contains( document.activeElement ) ) {
				event.preventDefault();
				first.focus();
			}
		}
	}

	function openModal() {
		var overlay = el( 'div', { class: 'qersh-wc-modal' } );
		var dialog = el( 'div', {
			class: 'qersh-wc-modal-dialog',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'qersh-wc-modal-title',
		} );
		var main = el( 'div', { class: 'qersh-wc-modal-main' } );
		main.appendChild( el( 'h2', { id: 'qersh-wc-modal-title', tabindex: '-1' }, __( 'Your developer\'s link with the connection key (shown once)', 'qersh-for-woocommerce' ) ) );
		main.appendChild( el( 'p', { class: 'qersh-wc-modal-warning' }, __( 'This link carries the connection key. Anyone who gets it can send your store fake transfers that confirm orders. Open it, or scan its code, on the wallet\'s phone only; do not send it to anyone, take a picture of it or share your screen. It is shown this one time only: if you close this window before using it, generate a new link.', 'qersh-for-woocommerce' ) ) );
		main.appendChild( el( 'p', {}, __( 'On the wallet\'s phone, open the link in Qersh, save the wallet or tap “Update wallet”, then tap “Test connection”. You do not need to paste anything here.', 'qersh-for-woocommerce' ) ) );
		var code = el( 'code', { dir: 'ltr', class: 'qersh-wc-link' } );
		code.textContent = masked( secretLink );
		main.appendChild( el( 'p' ) ).appendChild( code );

		var buttons = el( 'p', { class: 'qersh-wc-modal-buttons' } );
		var qrButton = el( 'button', { type: 'button', class: 'button' }, __( 'Show QR code', 'qersh-for-woocommerce' ) );
		var copyButton = el( 'button', { type: 'button', class: 'button' }, __( 'Copy link', 'qersh-for-woocommerce' ) );
		var openButton = el( 'button', { type: 'button', class: 'button' }, __( 'Open in Qersh', 'qersh-for-woocommerce' ) );
		var closeButton = el( 'button', { type: 'button', class: 'button button-primary' }, __( 'Close', 'qersh-for-woocommerce' ) );
		buttons.appendChild( qrButton );
		buttons.appendChild( copyButton );
		if ( /Android/i.test( window.navigator.userAgent || '' ) ) {
			buttons.appendChild( openButton );
		}
		buttons.appendChild( closeButton );
		main.appendChild( buttons );

		var canvas = el( 'canvas', { class: 'qersh-wc-qr' } );
		canvas.hidden = true;
		main.appendChild( canvas );
		var status = el( 'p', { class: 'qersh-wc-modal-status', role: 'status', 'aria-live': 'polite' } );
		main.appendChild( status );

		var ask = el( 'div', { class: 'qersh-wc-modal-ask' } );
		ask.hidden = true;
		ask.appendChild( el( 'p', {}, __( 'Did you open this link on the wallet\'s phone?', 'qersh-for-woocommerce' ) ) );
		var askButtons = el( 'p', { class: 'qersh-wc-modal-buttons' } );
		var openedButton = el( 'button', { type: 'button', class: 'button button-primary' }, __( 'I opened it on the phone', 'qersh-for-woocommerce' ) );
		var cancelButton = el( 'button', { type: 'button', class: 'button qersh-wc-danger' }, __( 'Cancel this link', 'qersh-for-woocommerce' ) );
		askButtons.appendChild( openedButton );
		askButtons.appendChild( cancelButton );
		ask.appendChild( askButtons );

		dialog.appendChild( main );
		dialog.appendChild( ask );
		overlay.appendChild( dialog );
		document.body.appendChild( overlay );
		document.body.classList.add( 'qersh-wc-modal-open' );
		modal = overlay;
		document.addEventListener( 'keydown', onKeydown, true );
		// A click outside the window does nothing: there is no listener on the overlay on purpose.

		qrButton.addEventListener( 'click', function () {
			if ( ! secretLink || qrButton.disabled ) {
				return;
			}
			if ( drawQr( canvas, secretLink ) ) {
				canvas.hidden = false;
				qrButton.disabled = true;
				qrTimer = window.setTimeout( function () {
					clearCanvas( canvas );
					status.textContent = __( 'The QR code was hidden after 5 minutes. If you still need it, close this window and generate a new link.', 'qersh-for-woocommerce' );
				}, config.qrHideMs || 300000 );
			}
		} );
		copyButton.addEventListener( 'click', function () {
			if ( ! secretLink ) {
				return;
			}
			copyText(
				secretLink,
				function ( ok ) {
					status.textContent = ok ? __( 'Copied. Paste it only on the wallet\'s phone.', 'qersh-for-woocommerce' ) : __( 'Your browser did not allow copying. Use “Show QR code” instead.', 'qersh-for-woocommerce' );
				},
				true
			);
		} );
		openButton.addEventListener( 'click', function () {
			if ( typeof secretLink === 'string' && 0 === secretLink.indexOf( 'qersh://setup?' ) ) {
				window.location.assign( secretLink );
			}
		} );
		closeButton.addEventListener( 'click', function () {
			// Closing ends the link's one showing: the key leaves the page now; only its fingerprint stays, for
			// "Cancel this link".
			secretLink = null;
			if ( qrTimer ) {
				window.clearTimeout( qrTimer );
				qrTimer = null;
			}
			clearCanvas( canvas );
			code.textContent = '';
			main.hidden = true;
			ask.hidden = false;
			openedButton.focus();
		} );
		openedButton.addEventListener( 'click', function () {
			wipe();
			window.location.reload();
		} );
		cancelButton.addEventListener( 'click', function () {
			var meta = linkMeta;
			wipe();
			if ( ! meta || ! config.postUrl ) {
				window.location.reload();
				return;
			}
			var form = el( 'form', { method: 'post', action: config.postUrl } );
			var fields = {
				action: 'qersh_wc_key_pending_cancel',
				_wpnonce: config.cancelNonce || '',
				wallet: meta.wallet,
				fp: meta.fingerprint,
				created_at: String( meta.createdAt ),
			};
			Object.keys( fields ).forEach( function ( name ) {
				var input = el( 'input', { type: 'hidden', name: name } );
				input.value = fields[ name ];
				form.appendChild( input );
			} );
			document.body.appendChild( form );
			form.submit();
		} );

		main.querySelector( 'h2' ).focus();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.qersh-wc-keylink' ) : null;
		if ( ! button || button.disabled || modal ) {
			return;
		}
		event.preventDefault();
		var wallet = button.getAttribute( 'data-qersh-keylink' ) || '';
		var card = button.closest( '.qersh-wc-card' );
		var error = card ? card.querySelector( '.qersh-wc-keylink-error' ) : null;
		if ( error ) {
			error.hidden = true;
			error.textContent = '';
		}
		var label = button.textContent;
		button.disabled = true;
		button.textContent = __( 'Generating…', 'qersh-for-woocommerce' );
		post( config.ajaxUrl, {
			action: 'qersh_wc_key_link',
			nonce: config.keyLinkNonce || '',
			wallet: wallet,
		} )
			.then( function ( response ) {
				return response.json().then(
					function ( data ) {
						return { status: response.status, data: data };
					},
					function () {
						return { status: response.status, data: null };
					}
				);
			} )
			.then( function ( result ) {
				button.disabled = false;
				button.textContent = label;
				var data = result.data || {};
				if ( 200 !== result.status || typeof data.link !== 'string' || 0 !== data.link.indexOf( 'qersh://setup?' ) ) {
					if ( error ) {
						error.textContent = typeof data.message === 'string' ? data.message : __( 'The link could not be generated. Reload the page and try again.', 'qersh-for-woocommerce' );
						error.hidden = false;
					}
					return;
				}
				secretLink = data.link;
				linkMeta = {
					wallet: wallet,
					fingerprint: String( data.fingerprint || '' ),
					createdAt: parseInt( data.created_at, 10 ) || 0,
				};
				data.link = null;
				openModal();
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = label;
				if ( error ) {
					error.textContent = __( 'The link could not be generated. Reload the page and try again.', 'qersh-for-woocommerce' );
					error.hidden = false;
				}
			} );
	} );

	window.addEventListener( 'pagehide', function () {
		wipe();
		wipePastes();
	} );
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			wipe();
			wipePastes();
		}
	} );
} )();
