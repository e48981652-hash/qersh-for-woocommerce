/**
 * Qersh for WooCommerce: the normalisers the storefront scripts share, copies of the PHP ones.
 *
 * - normalizePayer() is Core\Payer::normalize() (normalizePayer of the Qersh core).
 * - canonicalTyped() is Core\TxnId::canonical_typed() (docs/17 §4.10 steps 1-7). Both read
 *   tests/vectors/txn-normalize.json (tests/js/txn-normalize.test.mjs runs this file on it).
 * - checkPayer() and isStoreNumber() are PayerField::check() and PayerField::is_store_number() for one field.
 *
 * Loaded before payer-field.js, checkout-blocks.js and thankyou.js as window.qershWcNormalize; under Node it is a
 * CommonJS module. In a browser the global is always set, even when another script leaked a global `module`
 * (the storefront scripts stop when it is missing), and such a leaked `module` is left alone. Plain ES5, no build
 * step.
 */
( function ( root, factory ) {
	'use strict';
	var api = factory();
	var browser = typeof window !== 'undefined';
	if ( root && ( typeof root === 'object' || typeof root === 'function' ) ) {
		root.qershWcNormalize = api;
	}
	if ( ! browser && typeof module === 'object' && module && module.exports ) {
		module.exports = api;
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	// The shapes of Core\TxnId (PATTERNS, LENGTHS, CLASSES); the page passes the server's copy, these are the defaults.
	var SHAPES = {
		orange_cash: { pattern: '^(?:[0-9]{14}|[0-9]{10})$', lengths: [ 14, 10 ], cls: '[0-9]' }
	};

	var MAX_LENGTH = 400;

	// The escape of one code point, so the tables below stay plain ASCII in this file.
	function esc( code ) {
		return '\\' + 'u' + ( '000' + code.toString( 16 ) ).slice( -4 );
	}

	// A global character class from code points and [ from, to ] ranges.
	function charClass( items ) {
		var out = '';
		for ( var i = 0; i < items.length; i++ ) {
			out += typeof items[ i ] === 'number' ? esc( items[ i ] ) : esc( items[ i ][ 0 ] ) + '-' + esc( items[ i ][ 1 ] );
		}
		return new RegExp( '[' + out + ']', 'g' );
	}

	// Removed before reading, as ArabicNormalizer.kt drops them (TxnId::DROPPED): zero-width marks, LRM and RLM, bidi
	// embeddings, overrides and isolates, BOM, soft hyphen, tatweel, the Arabic letter mark, tashkeel, superscript alef.
	var DROPPED = charClass( [ [ 0x200B, 0x200F ], [ 0x202A, 0x202E ], [ 0x2066, 0x2069 ], 0xFEFF, 0x00AD, 0x0640, 0x061C, [ 0x064B, 0x0652 ], 0x0670 ] );

	// White space that becomes a space (TxnId::SPACES).
	var SPACES = charClass( [ 0x0009, 0x0020, 0x00A0, 0x1680, [ 0x2000, 0x200A ], 0x202F, 0x205F, 0x3000 ] );

	// Line and paragraph breaks that become "\n", so numbers are never joined across lines (TxnId::LINE_BREAKS).
	var LINE_BREAKS = charClass( [ [ 0x000A, 0x000D ], [ 0x001C, 0x001F ], 0x2028, 0x2029 ] );

	// Separators of the compact attempt, and the one character that may join two runs on a line (TxnId::SEPARATORS).
	var COMPACT_DROP = /[ \-.#:\n]/g;
	var SEPARATOR_CLASS = '[ \\-.#:]';
	var SEPARATOR_ALL = /[ \-.#:]/g;

	// Characters read as their Latin forms (TxnId::normalize_text()), by code point; the digits are added below.
	var MAP = {};
	( function () {
		var pairs = [
			[ 0x066B, '.' ], // Arabic decimal separator.
			[ 0x066C, ',' ], // Arabic thousands separator.
			[ 0x060C, ',' ], // Arabic comma.
			[ 0x061B, ';' ], // Arabic semicolon.
			[ 0x061F, '?' ], // Arabic question mark.
			[ 0x066A, '%' ], // Arabic percent sign.
			[ 0xFF1A, ':' ], // Full-width colon.
			[ 0xFF0C, ',' ], // Full-width comma.
			[ 0xFF0E, '.' ], // Full-width full stop.
			[ 0xFF03, '#' ], // Full-width number sign.
			[ 0xFF0D, '-' ], // Full-width hyphen-minus.
			[ 0x2010, '-' ], // Hyphen.
			[ 0x2011, '-' ], // Non-breaking hyphen.
			[ 0x2012, '-' ], // Figure dash.
			[ 0x2013, '-' ], // En dash.
			[ 0x2014, '-' ], // Em dash.
			[ 0x2212, '-' ] // Minus sign.
		];
		for ( var i = 0; i < pairs.length; i++ ) {
			MAP[ String.fromCharCode( pairs[ i ][ 0 ] ) ] = pairs[ i ][ 1 ];
		}
	}() );
	( function () {
		for ( var d = 0; d <= 9; d++ ) {
			MAP[ String.fromCharCode( 0x0660 + d ) ] = String( d ); // Arabic-Indic.
			MAP[ String.fromCharCode( 0x06F0 + d ) ] = String( d ); // Extended Arabic-Indic (Persian).
		}
	}() );

	function has( obj, key ) {
		return Object.prototype.hasOwnProperty.call( obj, key );
	}

	/**
	 * `01xxxxxxxxx` from any spelling (+20 1…, 0020…, spaces, Arabic-Indic or Persian digits), or an InstaPay
	 * address `name@instapay` (a bare `name` gets the suffix). Null for anything else, including a masked number.
	 *
	 * @param {*} raw Value.
	 * @return {string|null} Normalised payer.
	 */
	function normalizePayer( raw ) {
		var s = String( raw === null || raw === undefined ? '' : raw ).trim().toLowerCase();
		if ( ! s ) {
			return null;
		}
		if ( s.indexOf( '@' ) !== -1 ) {
			return /^[a-z0-9._-]{2,64}@instapay$/.test( s ) ? s : null;
		}
		var d = '';
		for ( var i = 0; i < s.length; i++ ) {
			var c = s.charCodeAt( i );
			if ( c >= 0x30 && c <= 0x39 ) {
				d += s.charAt( i );
			} else if ( c >= 0x660 && c <= 0x669 ) {
				d += String.fromCharCode( c - 0x660 + 0x30 );
			} else if ( c >= 0x6f0 && c <= 0x6f9 ) {
				d += String.fromCharCode( c - 0x6f0 + 0x30 );
			}
		}
		if ( d.indexOf( '00' ) === 0 ) {
			d = d.slice( 2 ); // 0020…
		}
		if ( d.indexOf( '20' ) === 0 && d.length >= 12 ) {
			d = d.slice( 2 ); // 201… or 2001…
		}
		if ( d.length === 10 && d.charAt( 0 ) === '1' ) {
			d = '0' + d; // 1xxxxxxxxx
		}
		if ( /^01[0-9]{9}$/.test( d ) ) {
			return d;
		}
		if ( /^[a-z0-9._-]{2,64}$/.test( s ) && /[a-z]/.test( s ) ) {
			return s + '@instapay'; // handle typed without the suffix
		}
		return null;
	}

	/**
	 * Whether a normalised payer is one of the store's wallets: the same last nine digits, or the same address.
	 *
	 * @param {string}   payer        Normalised payer.
	 * @param {string[]} storeNumbers The store's receive addresses.
	 * @return {boolean} True for a store wallet.
	 */
	function isStoreNumber( payer, storeNumbers ) {
		var handle = payer.indexOf( '@' ) !== -1;
		var list = storeNumbers || [];
		for ( var i = 0; i < list.length; i++ ) {
			var own = normalizePayer( list[ i ] );
			if ( own === null ) {
				continue;
			}
			if ( handle ) {
				if ( own === payer ) {
					return true;
				}
			} else if ( own.indexOf( '@' ) === -1 && own.slice( -9 ) === payer.slice( -9 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The payer field check of PayerField::check() (without the wallet choice).
	 *
	 * @param {string}   raw          Field value.
	 * @param {boolean}  instapay     Whether an InstaPay address is accepted.
	 * @param {string[]} storeNumbers The store's receive addresses.
	 * @return {{ok: boolean, payer: (string|undefined), error: (string|undefined)}} Result.
	 */
	function checkPayer( raw, instapay, storeNumbers ) {
		var payer = normalizePayer( raw );
		if ( payer === null || ( payer.indexOf( '@' ) !== -1 && ! instapay ) ) {
			return { ok: false, error: 'payer_invalid' };
		}
		if ( isStoreNumber( payer, storeNumbers ) ) {
			return { ok: false, error: 'payer_store' };
		}
		return { ok: true, payer: payer };
	}

	function error( code ) {
		return { ok: false, error: code };
	}

	function normalizeText( text ) {
		var out = '';
		for ( var i = 0; i < text.length; i++ ) {
			var ch = text.charAt( i );
			out += has( MAP, ch ) ? MAP[ ch ] : ch;
		}
		return out.replace( DROPPED, '' ).replace( LINE_BREAKS, '\n' ).replace( SPACES, ' ' );
	}

	function ownNumbers( payerKey, storeNumbers ) {
		var own = {};
		var list = [ payerKey ].concat( storeNumbers || [] );
		for ( var i = 0; i < list.length; i++ ) {
			var n = typeof list[ i ] === 'string' ? normalizePayer( list[ i ] ) : null;
			if ( n !== null ) {
				own[ n ] = true;
			}
		}
		return own;
	}

	function isOwn( value, own ) {
		var n = normalizePayer( value );
		return n !== null && has( own, n );
	}

	function isPhone( value, hasShape ) {
		if ( ! /^\+?[0-9]+$/.test( value ) ) {
			return false;
		}
		var n = normalizePayer( value );
		if ( n === null || n.indexOf( '@' ) !== -1 ) {
			return false;
		}
		return ! hasShape || /^(?:\+|00)/.test( value );
	}

	function numbers( shape, text ) {
		var values = [];
		var group = new RegExp( shape.cls + '+(?:' + SEPARATOR_CLASS + shape.cls + '+)*', 'g' );
		var run = new RegExp( shape.cls + '+', 'g' );
		var m;
		while ( ( m = group.exec( text ) ) !== null ) {
			var g = m[ 0 ];
			var joined = g.replace( SEPARATOR_ALL, '' );
			if ( joined !== g && shape.lengths.indexOf( joined.length ) !== -1 ) {
				values.push( joined );
				continue;
			}
			var runs = g.match( run ) || [];
			for ( var i = 0; i < runs.length; i++ ) {
				values.push( runs[ i ] );
			}
		}
		return values;
	}

	/**
	 * Reads the transaction id out of what a customer typed or pasted (TxnId::canonical_typed()).
	 *
	 * @param {string}   provider     Provider of the order's wallet.
	 * @param {*}        raw          What the customer wrote.
	 * @param {?string}  payerKey     The order's payer key.
	 * @param {string[]} storeNumbers The store's wallet numbers.
	 * @param {Object=}  shapes       Shapes by provider (defaults to the built-in copy).
	 * @return {{ok: boolean, txn: (string|undefined), error: (string|undefined)}} Result.
	 */
	function canonicalTyped( provider, raw, payerKey, storeNumbers, shapes ) {
		var table = shapes || SHAPES;
		if ( raw === null || raw === undefined ) {
			return error( 'qersh_txn_empty' );
		}
		if ( typeof raw !== 'string' ) {
			return error( 'qersh_txn_format' );
		}
		if ( raw.length > MAX_LENGTH ) {
			return error( 'qersh_txn_too_long' );
		}
		var text = normalizeText( raw );
		var compact = text.replace( COMPACT_DROP, '' );
		if ( compact === '' ) {
			return error( 'qersh_txn_empty' );
		}
		if ( ! has( table, provider ) ) {
			return error( 'qersh_txn_format' );
		}
		var shape = table[ provider ];
		var own = ownNumbers( payerKey, storeNumbers );
		var matches = new RegExp( shape.pattern ).test( compact );
		if ( isOwn( compact, own ) || isPhone( compact, matches ) ) {
			return error( 'qersh_txn_wallet_number' );
		}
		if ( matches ) {
			return { ok: true, txn: compact };
		}
		var candidates = [];
		var excluded = 0;
		var values = numbers( shape, text );
		for ( var i = 0; i < values.length; i++ ) {
			var v = values[ i ];
			if ( shape.lengths.indexOf( v.length ) === -1 ) {
				continue;
			}
			if ( isOwn( v, own ) || isPhone( v, true ) ) {
				excluded++;
				continue;
			}
			if ( candidates.indexOf( v ) === -1 ) {
				candidates.push( v );
			}
		}
		if ( candidates.length === 1 ) {
			return { ok: true, txn: candidates[ 0 ] };
		}
		if ( candidates.length > 1 ) {
			return error( 'qersh_txn_several' );
		}
		return error( excluded > 0 ? 'qersh_txn_wallet_number' : 'qersh_txn_format' );
	}

	return {
		SHAPES: SHAPES,
		MAX_LENGTH: MAX_LENGTH,
		normalizePayer: normalizePayer,
		isStoreNumber: isStoreNumber,
		checkPayer: checkPayer,
		canonicalTyped: canonicalTyped
	};
} ) );
