/**
 * Payer identifiers, as kit/reference/match.mjs normalises them.
 *
 * `01xxxxxxxxx` from any spelling (+20 1…, +20 01…, 0020…, spaces, Arabic-Indic or Persian digits), or an InstaPay
 * handle as the app delivers it: `name@instapay` lower-case (a bare `name` gets the suffix). Null for anything else,
 * including a masked number such as `0111***3445`.
 */
export function normalizePayer(raw) {
    const s = String(raw ?? '').trim().toLowerCase();
    if (!s)
        return null;
    if (s.includes('@'))
        return /^[a-z0-9._-]{2,64}@instapay$/.test(s) ? s : null;
    let d = '';
    for (const ch of s) {
        const c = ch.charCodeAt(0);
        if (c >= 0x30 && c <= 0x39)
            d += ch;
        else if (c >= 0x660 && c <= 0x669)
            d += String.fromCharCode(c - 0x660 + 0x30);
        else if (c >= 0x6f0 && c <= 0x6f9)
            d += String.fromCharCode(c - 0x6f0 + 0x30);
    }
    if (d.startsWith('00'))
        d = d.slice(2); // 0020…
    if (d.startsWith('20') && d.length >= 12)
        d = d.slice(2); // 201… or 2001…
    if (d.length === 10 && d.startsWith('1'))
        d = '0' + d; // 1xxxxxxxxx
    if (/^01[0-9]{9}$/.test(d))
        return d;
    if (/^[a-z0-9._-]{2,64}$/.test(s) && /[a-z]/.test(s))
        return s + '@instapay'; // handle typed without the suffix
    return null;
}
/**
 * The payer key of a transfer as the reference matcher derives it: the number when it normalises, otherwise the
 * handle. A masked number therefore falls through to the handle instead of blocking the match.
 */
export function payerKeyOf(counterparty) {
    if (!counterparty)
        return null;
    return normalizePayer(counterparty.number) ?? normalizePayer(counterparty.handle);
}
