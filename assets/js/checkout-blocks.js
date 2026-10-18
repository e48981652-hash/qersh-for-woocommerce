/**
 * Qersh for WooCommerce: the wallet transfer method in the Blocks checkout (docs/17 §4.1, §6.1).
 *
 * Plain JavaScript with wp.element.createElement (no build step). Draws the wallet choice (when more than one
 * wallet is enabled: the merchant's label and the number, never a wallet type), the confirmation line and the payer
 * field (an e-wallet number only, as PayerField::check() takes it), then sends paymentMethodData { qersh_payer,
 * qersh_wallet } in onPaymentSetup. canMakePayment() follows Gateway::is_available(): EGP, a wallet, and the store's
 * minimum and maximum. The server checks the field again (Gateway::validate_fields()).
 */
( function ( wp, wc, N ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.i18n || ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings || ! N ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var decode = wp.htmlEntities && wp.htmlEntities.decodeEntities ? wp.htmlEntities.decodeEntities : function ( s ) {
		return s;
	};

	var data = wc.wcSettings.getSetting( 'qersh_data', {} ) || {};
	var wallets = Array.isArray( data.wallets ) ? data.wallets : [];
	var storeNumbers = Array.isArray( data.store_numbers ) ? data.store_numbers : [];
	var title = decode( data.title || '' ) || __( 'E-wallet transfer', 'qersh-for-woocommerce' );

	function findWallet( id ) {
		for ( var i = 0; i < wallets.length; i++ ) {
			if ( wallets[ i ].id === id ) {
				return wallets[ i ];
			}
		}
		return null;
	}

	// PayerField::message().
	function messageFor( code ) {
		if ( code === 'wallet_choice' ) {
			return __( 'Choose the wallet you will transfer to.', 'qersh-for-woocommerce' );
		}
		if ( code === 'payer_store' ) {
			return __( "This is the store's wallet number. Enter the wallet number you will transfer from.", 'qersh-for-woocommerce' );
		}
		return __( 'Enter an Egyptian wallet number of 11 digits starting with 01.', 'qersh-for-woocommerce' );
	}

	function Label() {
		return el( 'span', { className: 'qersh-blocks-label' }, title );
	}

	function Edit() {
		return el(
			'div',
			{ className: 'qersh-payer qersh-payer--blocks' },
			data.description ? el( 'p', { className: 'qersh-desc' }, decode( data.description ) ) : null
		);
	}

	// What the customer chose and typed. Blocks draws a method's content only while the method is selected, so
	// choosing another method and coming back mounts Content again: these live here, not in the component, and the
	// billing phone never replaces a number the customer typed (docs/17 §4.1, §6.1), as in payer-field.js.
	var memo = {
		wallet: wallets.length === 1 ? wallets[ 0 ].id : '',
		value: '',
		touched: false,
		prefilled: ''
	};

	// useState whose value starts from the memo and is written back to it on every change.
	function useMemoState( key, initial ) {
		var state = useState( initial );
		return [
			state[ 0 ],
			function ( v ) {
				memo[ key ] = v;
				state[ 1 ]( v );
			}
		];
	}

	function Content( props ) {
		var registration = props.eventRegistration || {};
		var emit = props.emitResponse || {};
		var single = wallets.length === 1;

		var walletState = useMemoState( 'wallet', single ? wallets[ 0 ].id : ( findWallet( memo.wallet ) ? memo.wallet : '' ) );
		var wallet = walletState[ 0 ];
		var setWallet = walletState[ 1 ];

		var valueState = useMemoState( 'value', memo.value );
		var value = valueState[ 0 ];
		var setValue = valueState[ 1 ];

		// Each error in its own place: a wrong number on the field, a missing wallet on the radio group.
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];
		var walletErrorState = useState( '' );
		var walletError = walletErrorState[ 0 ];
		var setWalletError = walletErrorState[ 1 ];

		var billing = props.billing || {};
		var address = billing.billingAddress || billing.billingData || {};
		var phone = address.phone || '';

		// The billing phone fills the field only while it is empty and untouched.
		useEffect( function () {
			if ( memo.touched ) {
				return;
			}
			if ( value !== '' && value !== memo.prefilled ) {
				return;
			}
			var n = N.numberOf( phone );
			if ( n && ! N.isStoreNumber( n, storeNumbers ) ) {
				memo.prefilled = n;
				setValue( n );
			} else if ( memo.prefilled && value === memo.prefilled ) {
				memo.prefilled = '';
				setValue( '' );
			}
		}, [ phone ] ); // eslint-disable-line react-hooks/exhaustive-deps

		// The payer field alone (the wallet choice is walletMissing()).
		function check( sending ) {
			if ( value.trim() === '' && ! sending ) {
				return { ok: true };
			}
			// No address: a number only, as PayerField::check() takes it.
			var res = N.checkPayer( value, storeNumbers );
			if ( ! res.ok ) {
				return { ok: false, message: messageFor( res.error ) };
			}
			return { ok: true, payer: res.payer, wallet: single ? wallets[ 0 ].id : wallet };
		}

		function walletMissing() {
			return ! single && ! findWallet( wallet );
		}

		function focusFirstWallet() {
			var page = typeof window !== 'undefined' ? window.document : null;
			var first = page && wallets.length ? page.getElementById( 'qersh_wallet_blocks_' + wallets[ 0 ].id ) : null;
			if ( first && typeof first.focus === 'function' ) {
				first.focus();
			}
		}

		useEffect( function () {
			if ( typeof registration.onPaymentSetup !== 'function' ) {
				return undefined;
			}
			return registration.onPaymentSetup( function () {
				var res = check( true );
				var missing = walletMissing();
				var types = emit.responseTypes || { ERROR: 'error', SUCCESS: 'success' };
				setWalletError( missing ? messageFor( 'wallet_choice' ) : '' );
				setError( res.ok ? '' : res.message );
				if ( missing || ! res.ok ) {
					if ( missing ) {
						focusFirstWallet();
					}
					return {
						type: types.ERROR,
						message: missing ? messageFor( 'wallet_choice' ) : res.message,
						messageContext: emit.noticeContexts ? emit.noticeContexts.PAYMENTS : undefined
					};
				}
				setValue( res.payer );
				return {
					type: types.SUCCESS,
					meta: { paymentMethodData: { qersh_payer: res.payer, qersh_wallet: res.wallet } }
				};
			} );
		}, [ registration.onPaymentSetup, value, wallet ] ); // eslint-disable-line react-hooks/exhaustive-deps

		function chooseWallet( w ) {
			setWallet( w.id );
			setWalletError( '' );
		}

		var children = [];
		if ( data.description ) {
			children.push( el( 'p', { className: 'qersh-desc', key: 'desc' }, decode( data.description ) ) );
		}

		if ( single ) {
			children.push( el( 'p', { className: 'qersh-confirm', key: 'confirm' }, wallets[ 0 ].confirm ) );
		} else if ( wallets.length > 1 ) {
			children.push( el(
				'fieldset',
				{
					className: 'qersh-wallets' + ( walletError ? ' qersh-wallets--invalid' : '' ),
					key: 'wallets',
					id: 'qersh_wallets_blocks',
					role: 'radiogroup',
					'aria-required': 'true',
					'aria-invalid': walletError ? 'true' : undefined
				},
				el(
					'legend',
					{ className: 'qersh-wallets__legend' },
					__( 'Which wallet will you transfer to?', 'qersh-for-woocommerce' ),
					' ',
					el( 'span', { className: 'required', 'aria-hidden': 'true' }, '*' )
				),
				wallets.map( function ( w ) {
					var id = 'qersh_wallet_blocks_' + w.id;
					return el(
						'div',
						{ className: 'qersh-wallet', key: w.id },
						el( 'input', {
							type: 'radio',
							name: 'qersh_wallet_blocks',
							id: id,
							value: w.id,
							checked: wallet === w.id,
							'aria-describedby': id + '_confirm qersh_wallet_blocks_error',
							onChange: function () {
								chooseWallet( w );
							}
						} ),
						// The merchant's label when there is one, and the number either way.
						el(
							'label',
							{ htmlFor: id },
							w.label ? el( 'span', { className: 'qersh-wallet__name' }, w.label ) : null,
							w.label ? ' ' : null,
							el( 'bdi', { dir: 'ltr' }, w.display )
						),
						el( 'p', { className: 'qersh-confirm', id: id + '_confirm' }, w.confirm )
					);
				} ),
				el( 'span', { className: 'qersh-error', id: 'qersh_wallet_blocks_error', role: 'alert', hidden: ! walletError }, walletError )
			) );
		}

		children.push( el(
			'div',
			{ className: 'qersh-payer__row' + ( error ? ' has-error' : '' ), key: 'field' },
			el( 'label', { htmlFor: 'qersh_payer_blocks' }, __( 'Wallet number you will transfer from', 'qersh-for-woocommerce' ) ),
			el( 'input', {
				type: 'text',
				className: 'qersh-payer__input',
				id: 'qersh_payer_blocks',
				dir: 'ltr',
				inputMode: 'numeric',
				autoComplete: 'tel',
				maxLength: 80,
				value: value,
				'aria-required': 'true',
				'aria-invalid': error ? 'true' : undefined,
				'aria-describedby': 'qersh_payer_blocks_help qersh_payer_blocks_error',
				onChange: function ( e ) {
					memo.touched = true;
					memo.prefilled = '';
					setError( '' );
					setValue( e.target.value );
				},
				onBlur: function () {
					var res = check( false );
					if ( res.ok && res.payer ) {
						setValue( res.payer );
					}
					setError( res.ok ? '' : res.message );
				}
			} ),
			el( 'span', { className: 'qersh-help', id: 'qersh_payer_blocks_help' }, __( 'Transfer from this number, so the store can match your transfer to your order.', 'qersh-for-woocommerce' ) ),
			el( 'span', { className: 'qersh-error', id: 'qersh_payer_blocks_error', role: 'alert', hidden: ! error }, error )
		) );

		return el( 'div', { className: 'qersh-payer qersh-payer--blocks' }, children );
	}

	function canMakePayment( arg ) {
		if ( ! data.ready || wallets.length === 0 ) {
			return false;
		}
		var totals = arg && arg.cartTotals;
		if ( ! totals ) {
			return true;
		}
		if ( totals.currency_code && totals.currency_code !== ( data.currency || 'EGP' ) ) {
			return false;
		}
		var total = parseInt( totals.total_price, 10 );
		if ( isNaN( total ) ) {
			return true;
		}
		var unit = typeof totals.currency_minor_unit === 'number' ? totals.currency_minor_unit : 2;
		var minor = unit === 2 ? total : Math.round( total * Math.pow( 10, 2 - unit ) );
		if ( typeof data.min_total_minor === 'number' && minor < data.min_total_minor ) {
			return false;
		}
		if ( typeof data.max_total_minor === 'number' && minor > data.max_total_minor ) {
			return false;
		}
		return true;
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'qersh',
		label: el( Label ),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Edit ),
		canMakePayment: canMakePayment,
		supports: { features: Array.isArray( data.supports ) ? data.supports : [ 'products' ] }
	} );
}( window.wp, window.wc, window.qershWcNormalize ) );
