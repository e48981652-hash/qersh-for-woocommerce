/**
 * Qersh for WooCommerce: the normalisers the storefront scripts share, copies of the PHP ones.
 *
 * - normalizePayer() is Core\Payer::normalize() (normalizePayer of the Qersh core).
 * - canonicalTyped() is Core\TxnId::canonical_typed() (docs/17 §4.10), the same for every e-wallet: the plugin never
 *   knows a wallet's type, so an id is read into one canonical form and compared by exact equality. Both read
 *   tests/vectors/txn-normalize.json (tests/js/txn-normalize.test.mjs runs this file on it). normalizeText() is its
 *   steps 2 and 3.
 * - numberOf(), checkPayer() and isStoreNumber() are PayerField::number_of(), PayerField::check() and
 *   PayerField::is_store_number() for one field: the payer is an e-wallet number, never an address.
 * - hasRtl() is Format::has_rtl() on text: whether a value holds a right-to-left letter, so a localized time is
 *   isolated by its first strong letter and a number stays left to right (docs/17 §7).
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

	// The limits of Core\TxnId: the shortest and longest canonical id, and the longest input (the input's maxlength).
	var MIN_ID = 6;
	var MAX_ID = 40;
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

	// Right-to-left letters (Format::RTL_LETTERS): Hebrew and Arabic letters and their presentation forms, without the
	// Arabic-Indic digits and number signs. Not global, so test() keeps no state between calls.
	var RTL_LETTERS = new RegExp( charClass( [
		[ 0x05D0, 0x05EA ], [ 0x05EF, 0x05F2 ], [ 0x0620, 0x064A ], 0x066E, 0x066F, [ 0x0671, 0x06D3 ], 0x06D5, 0x06EE, 0x06EF,
		[ 0x06FA, 0x06FF ], [ 0x0750, 0x077F ], [ 0x0870, 0x0887 ], [ 0x0889, 0x088E ], [ 0x08A0, 0x08C9 ], 0xFB1D,
		[ 0xFB1F, 0xFB28 ], [ 0xFB2A, 0xFB4F ], [ 0xFB50, 0xFD3D ], [ 0xFD50, 0xFDFF ], [ 0xFE70, 0xFEFC ]
	] ).source );

	// The separators (TxnId::SEPARATORS): an id typed alone may be split by any of them, and one of them alone between
	// two runs on a line of a message joins them. COMPACT_DROP also drops the line breaks.
	var SEPARATORS = ' -.#:';
	var SEPARATOR_ALL = /[ \-.#:]/g;
	var COMPACT_DROP = /[ \-.#:\n]/g;
	var TRIM = /^[ \-.#:\n]+|[ \-.#:\n]+$/g;

	// What a message holds that is never an id, replaced by a line break in this order (TxnId::BREAKS): links, dates
	// with a day of 1 to 31 and a month of 1 to 12 (either order), times with an hour of 0 to 23 and minutes and
	// seconds of 0 to 59 (so digits that are no date or time are never cut from an id), amounts with decimals, and
	// numbers followed by a currency (the Arabic word for pound in both spellings, its abbreviation, EGP, LE). Group 1
	// keeps the character before the match; no look-behind (older Safari), so the patterns are the same in both
	// languages.
	var BREAKS = [
		/()(?:[Hh][Tt][Tt][Pp][Ss]?:\/\/|[Ww][Ww][Ww]\.)[^ \n]*/g,
		/(^|[^0-9])(?:[0-9]{4}-(?:0?[1-9]|1[0-2])-(?:0?[1-9]|[12][0-9]|3[01])|(?:(?:0?[1-9]|[12][0-9]|3[01])[\/\-](?:0?[1-9]|1[0-2])|(?:0?[1-9]|1[0-2])[\/\-](?:0?[1-9]|[12][0-9]|3[01]))[\/\-][0-9]{2,4})(?![0-9])/g,
		/(^|[^0-9])(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?![0-9])/g,
		/(^|[^0-9.])[0-9]+[.,][0-9]{1,2}(?![0-9])/g,
		/(^|[^0-9])[0-9]+(?= ?(?:جني[هة]|ج\.م|[Ee][Gg][Pp]|[Ll]\.?[Ee])(?![A-Za-z]))/g
	];

	// A mobile number in a form an id is never read as, with an Egyptian mobile prefix 010, 011, 012 or 015
	// (TxnId::PHONE).
	var PHONE = /^(?:01[0125][0-9]{8}|(?:\+|00)?200?1[0125][0-9]{8})$/;

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
	 * The payer number of a value (PayerField::number_of()): its normalised form when it is an e-wallet number
	 * (`01xxxxxxxxx`), else null. A handle, which normalizePayer() reads, is no payer here.
	 *
	 * @param {*} raw Value.
	 * @return {string|null} Normalised number.
	 */
	function numberOf( raw ) {
		var payer = normalizePayer( raw );
		return payer !== null && /^01[0-9]{9}$/.test( payer ) ? payer : null;
	}

	/**
	 * Whether a payer number is one of the store's wallet numbers: the same last nine digits
	 * (PayerField::is_store_number()). Anything that is not a number, on either side, is never a store number.
	 *
	 * @param {string}   payer        Normalised payer number (numberOf()).
	 * @param {string[]} storeNumbers The store's wallet numbers.
	 * @return {boolean} True for a store wallet.
	 */
	function isStoreNumber( payer, storeNumbers ) {
		if ( typeof payer !== 'string' || ! /^01[0-9]{9}$/.test( payer ) ) {
			return false;
		}
		var list = storeNumbers || [];
		for ( var i = 0; i < list.length; i++ ) {
			var own = numberOf( list[ i ] );
			if ( own !== null && own.slice( -9 ) === payer.slice( -9 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The payer field check of PayerField::check() (without the wallet choice): an e-wallet number in any spelling,
	 * never one of the store's own. An address or a bare name is refused like any other text.
	 *
	 * @param {string}   raw          Field value.
	 * @param {string[]} storeNumbers The store's wallet numbers.
	 * @return {{ok: boolean, payer: (string|undefined), error: (string|undefined)}} Result.
	 */
	function checkPayer( raw, storeNumbers ) {
		var payer = numberOf( raw );
		if ( payer === null ) {
			return { ok: false, error: 'payer_invalid' };
		}
		if ( isStoreNumber( payer, storeNumbers ) ) {
			return { ok: false, error: 'payer_store' };
		}
		return { ok: true, payer: payer };
	}

	/**
	 * Whether a text holds a right-to-left letter (Format::has_rtl()): then it is isolated by its first strong letter
	 * (dir="auto"), otherwise left to right. Digits of any script, Latin text and symbols do not count.
	 *
	 * @param {*} text Text.
	 * @return {boolean} True when the text has a Hebrew or Arabic letter.
	 */
	function hasRtl( text ) {
		return typeof text === 'string' && text !== '' && RTL_LETTERS.test( text );
	}

	function error( code ) {
		return { ok: false, error: code };
	}

	/**
	 * Steps 2 and 3 of TxnId::canonical_typed() (TxnId::normalize_text()): Arabic-Indic and Persian digits to Latin,
	 * bidi and invisible marks and tatweel removed, line breaks to "\n", other white space to a space, and the Arabic
	 * and full-width separators to their Latin forms.
	 *
	 * @param {string} text Text.
	 * @return {string} Normalised text.
	 */
	function normalizeText( text ) {
		var s = String( text === null || text === undefined ? '' : text );
		var out = '';
		for ( var i = 0; i < s.length; i++ ) {
			var ch = s.charAt( i );
			out += has( MAP, ch ) ? MAP[ ch ] : ch;
		}
		return out.replace( DROPPED, '' ).replace( LINE_BREAKS, '\n' ).replace( SPACES, ' ' );
	}

	// Step 4 (TxnId::hard_breaks()).
	function hardBreaks( text ) {
		for ( var i = 0; i < BREAKS.length; i++ ) {
			text = text.replace( BREAKS[ i ], '$1\n' );
		}
		return text;
	}

	// TxnId::canonical(): [0-9A-Za-z] only, a digit, MIN_ID to MAX_ID characters, in upper case; else null.
	function canonical( value ) {
		if ( value.length < MIN_ID || value.length > MAX_ID || ! /^[0-9A-Za-z]+$/.test( value ) || ! /[0-9]/.test( value ) ) {
			return null;
		}
		return value.toUpperCase();
	}

	// TxnId::own_numbers(): the payer key and store numbers that normalise to a mobile number, as a set.
	function ownNumbers( payerKey, storeNumbers ) {
		var own = {};
		var list = [ payerKey ].concat( storeNumbers || [] );
		for ( var i = 0; i < list.length; i++ ) {
			var n = typeof list[ i ] === 'string' ? numberOf( list[ i ] ) : null;
			if ( n !== null ) {
				own[ n ] = true;
			}
		}
		return own;
	}

	// TxnId::is_number(): a mobile number, or the payer key or a store number in any spelling.
	function isNumber( value, own ) {
		if ( ! /^\+?[0-9]+$/.test( value ) ) {
			return false;
		}
		if ( PHONE.test( value ) ) {
			return true;
		}
		var n = normalizePayer( value );
		return n !== null && has( own, n );
	}

	// Step 5 (TxnId::id_alone()): the id typed alone, a refusal, or null to read the text as a message.
	function idAlone( text, own ) {
		var line = text.replace( TRIM, '' );
		if ( line.indexOf( '\n' ) !== -1 ) {
			return null;
		}
		var compact = line.replace( SEPARATOR_ALL, '' );
		if ( ! /^\+?[0-9A-Za-z]+$/.test( compact ) ) {
			return null;
		}
		var runs = line.match( /[0-9A-Za-z]+/g ) || [];
		for ( var i = 0; i < runs.length; i++ ) {
			if ( ! /[0-9]/.test( runs[ i ] ) || ( runs.length > 1 && isNumber( runs[ i ], own ) ) ) {
				return null;
			}
		}
		if ( isNumber( compact, own ) ) {
			return error( 'qersh_txn_wallet_number' );
		}
		var id = canonical( compact );
		return id === null ? null : { ok: true, txn: id };
	}

	// Step 6 (TxnId::numbers()): runs with a digit joined by one separator on a line form a group, which a word or a
	// number ends (the number is a value of its own); a group gives its runs joined, or each run past MAX_ID.
	function numbers( text, own ) {
		var groups = [];
		var group = [];
		var end = -1;
		var re = /[0-9A-Za-z]+/g;
		var m;
		while ( ( m = re.exec( text ) ) !== null ) {
			var run = m[ 0 ];
			if ( ! /[0-9]/.test( run ) || isNumber( run, own ) ) {
				groups.push( group );
				group = [];
				if ( /[0-9]/.test( run ) ) {
					groups.push( [ run ] );
				}
				continue;
			}
			if ( group.length === 0 || m.index !== end + 1 || SEPARATORS.indexOf( text.charAt( end ) ) === -1 ) {
				groups.push( group );
				group = [];
			}
			group.push( run );
			end = m.index + run.length;
		}
		groups.push( group );

		var values = [];
		for ( var i = 0; i < groups.length; i++ ) {
			var joined = groups[ i ].join( '' );
			if ( joined === '' ) {
				continue;
			}
			if ( joined.length > MAX_ID ) {
				values = values.concat( groups[ i ] );
				continue;
			}
			values.push( joined );
		}
		return values;
	}

	/**
	 * Reads the transaction id out of what a customer typed or pasted (TxnId::canonical_typed()), whatever the
	 * wallet: the id alone, split by spaces or `- . # :`, or the whole wallet message.
	 *
	 * @param {*}         raw          What the customer wrote.
	 * @param {?string=}  payerKey     The order's payer key ('' or null when it has none).
	 * @param {string[]=} storeNumbers The store's wallet numbers.
	 * @return {{ok: boolean, txn: (string|undefined), error: (string|undefined)}} Result.
	 */
	function canonicalTyped( raw, payerKey, storeNumbers ) {
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
		if ( text.replace( COMPACT_DROP, '' ) === '' ) {
			return error( 'qersh_txn_empty' );
		}
		text = hardBreaks( text );
		var own = ownNumbers( payerKey, storeNumbers );

		var alone = idAlone( text, own );
		if ( alone !== null ) {
			return alone;
		}

		var candidates = [];
		var excluded = 0;
		var values = numbers( text, own );
		for ( var i = 0; i < values.length; i++ ) {
			if ( isNumber( values[ i ], own ) ) {
				excluded++;
				continue;
			}
			var id = canonical( values[ i ] );
			if ( id !== null && candidates.indexOf( id ) === -1 ) {
				candidates.push( id );
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
		MIN_ID: MIN_ID,
		MAX_ID: MAX_ID,
		MAX_LENGTH: MAX_LENGTH,
		normalizeText: normalizeText,
		normalizePayer: normalizePayer,
		numberOf: numberOf,
		isStoreNumber: isStoreNumber,
		checkPayer: checkPayer,
		canonicalTyped: canonicalTyped,
		hasRtl: hasRtl
	};
} ) );
