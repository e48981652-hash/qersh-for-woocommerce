/**
 * Qersh for WooCommerce: the payer field of the classic checkout and of "Pay for order" (docs/17 §4.1, §6.1).
 *
 * - Fills the field from the billing phone only while it is empty and the customer has not touched it (the
 *   checkout refresh replaces the payment box, so the "touched" state lives here, not on the element).
 * - Normalises the value when the field loses focus, and shows the error in place before the order is sent.
 * - The InstaPay switch follows the chosen wallet until the customer changes it.
 * The server checks everything again (Gateway::validate_fields()). Plain ES5, no build step.
 */
( function ( win, doc ) {
	'use strict';

	var N = win.qershWcNormalize;
	if ( ! N ) {
		return;
	}

	var touched = false;
	var prefilled = '';
	var kindChosen = false;

	function root() {
		return doc.querySelector( '[data-qersh-payer]' );
	}

	function field() {
		return doc.getElementById( 'qersh_payer' );
	}

	function storeNumbers( box ) {
		try {
			var list = JSON.parse( box.getAttribute( 'data-store-numbers' ) || '[]' );
			return Array.isArray( list ) ? list : [];
		} catch ( e ) {
			return [];
		}
	}

	function chosenWallet( box ) {
		var inputs = box.querySelectorAll( 'input[name="qersh_wallet"]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].type === 'hidden' || inputs[ i ].checked ) {
				return inputs[ i ];
			}
		}
		return null;
	}

	function walletCount( box ) {
		return box.querySelectorAll( 'input[name="qersh_wallet"]' ).length;
	}

	function kind() {
		var instapay = doc.getElementById( 'qersh_payer_kind_instapay' );
		return instapay && instapay.checked ? 'instapay' : 'number';
	}

	function setError( box, message ) {
		var el = field();
		var out = doc.getElementById( 'qersh_payer_error' );
		if ( out ) {
			out.textContent = message;
			out.hidden = message === '';
		}
		if ( el ) {
			if ( message ) {
				el.setAttribute( 'aria-invalid', 'true' );
			} else {
				el.removeAttribute( 'aria-invalid' );
			}
		}
		var row = box.querySelector( '.qersh-payer__row' );
		if ( row ) {
			row.classList.toggle( 'woocommerce-invalid', message !== '' );
		}
	}

	function applyKind( box ) {
		var el = field();
		if ( ! el ) {
			return;
		}
		var instapay = kind() === 'instapay';
		var label = box.querySelector( '[data-qersh-payer-label]' );
		if ( label ) {
			label.textContent = box.getAttribute( instapay ? 'data-label-instapay' : 'data-label-number' ) || label.textContent;
		}
		el.setAttribute( 'inputmode', instapay ? 'email' : 'numeric' );
		el.setAttribute( 'autocomplete', instapay ? 'off' : 'tel' );
		if ( instapay && ! touched && prefilled && el.value === prefilled ) {
			el.value = '';
			prefilled = '';
		}
	}

	function applyWallet( box ) {
		var wallet = chosenWallet( box );
		var help = doc.getElementById( 'qersh_payer_help' );
		if ( help ) {
			var decision = wallet ? wallet.getAttribute( 'data-decision' ) === '1' : box.getAttribute( 'data-all-decision' ) === '1';
			help.textContent = box.getAttribute( decision ? 'data-help-decision' : 'data-help-auto' ) || help.textContent;
		}
		var toInstapay = doc.getElementById( 'qersh_payer_kind_instapay' );
		var toNumber = doc.getElementById( 'qersh_payer_kind_number' );
		if ( wallet && toInstapay && toNumber && ! kindChosen ) {
			var instapay = wallet.getAttribute( 'data-instapay' ) === '1';
			toInstapay.checked = instapay;
			toNumber.checked = ! instapay;
		}
		applyKind( box );
	}

	function prefill( box ) {
		var el = field();
		if ( ! el || touched || kind() !== 'number' ) {
			return;
		}
		if ( el.value !== '' && el.value !== prefilled ) {
			return;
		}
		var phone = doc.getElementById( 'billing_phone' );
		var n = N.normalizePayer( phone ? phone.value : '' );
		if ( n && n.indexOf( '@' ) === -1 && ! N.isStoreNumber( n, storeNumbers( box ) ) ) {
			el.value = n;
			prefilled = n;
		} else if ( prefilled && el.value === prefilled ) {
			el.value = '';
			prefilled = '';
		}
	}

	// The message for the current value, or '' when it is fine. Empty is an error only when sending.
	function problem( box, sending ) {
		var el = field();
		if ( ! el ) {
			return '';
		}
		if ( walletCount( box ) > 1 && ! chosenWallet( box ) ) {
			return sending ? box.getAttribute( 'data-msg-choice' ) || '' : '';
		}
		if ( el.value.trim() === '' && ! sending ) {
			return '';
		}
		var res = N.checkPayer( el.value, box.getAttribute( 'data-instapay' ) === '1', storeNumbers( box ) );
		if ( res.ok ) {
			el.value = res.payer;
			return '';
		}
		return box.getAttribute( res.error === 'payer_store' ? 'data-msg-store' : 'data-msg-invalid' ) || '';
	}

	function selectedHere() {
		var chosen = doc.querySelector( 'input[name="payment_method"]:checked' );
		if ( chosen ) {
			return chosen.value === 'qersh';
		}
		return !! root();
	}

	function validateForSend() {
		var box = root();
		if ( ! box || ! selectedHere() ) {
			return true;
		}
		var message = problem( box, true );
		setError( box, message );
		if ( message ) {
			var el = field();
			if ( el && typeof el.focus === 'function' ) {
				el.focus();
			}
			return false;
		}
		return true;
	}

	function init() {
		var box = root();
		if ( ! box ) {
			return;
		}
		applyWallet( box );
		prefill( box );
	}

	doc.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( ! t ) {
			return;
		}
		if ( t.id === 'qersh_payer' ) {
			touched = true;
			prefilled = '';
			var box = root();
			if ( box ) {
				setError( box, '' );
			}
		} else if ( t.id === 'billing_phone' ) {
			var b = root();
			if ( b ) {
				prefill( b );
			}
		}
	}, true );

	doc.addEventListener( 'change', function ( e ) {
		var t = e.target;
		var box = root();
		if ( ! t || ! box ) {
			return;
		}
		if ( t.name === 'qersh_wallet' ) {
			applyWallet( box );
			prefill( box );
		} else if ( t.name === 'qersh_payer_kind' ) {
			kindChosen = true;
			applyKind( box );
			prefill( box );
		}
	}, true );

	doc.addEventListener( 'focusout', function ( e ) {
		if ( e.target && e.target.id === 'qersh_payer' ) {
			var box = root();
			if ( box ) {
				setError( box, problem( box, false ) );
			}
		}
	}, true );

	if ( win.jQuery ) {
		var $ = win.jQuery;
		$( doc.body ).on( 'updated_checkout payment_method_selected', init );
		// WooCommerce asks each method with triggerHandler() before it sends the classic checkout.
		$( 'form.checkout' ).on( 'checkout_place_order_qersh', function () {
			return validateForSend();
		} );
		// "Pay for order" submits the form itself; this runs before WooCommerce blocks the form (it binds at ready).
		$( 'form#order_review' ).on( 'submit', function ( e ) {
			if ( ! validateForSend() ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				return false;
			}
			return true;
		} );
	}

	if ( doc.readyState === 'loading' ) {
		doc.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window, document ) );
