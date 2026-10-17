/**
 * qersh.v1 signature: `X-Qersh-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256>` over the UTF-8 bytes of
 * `t + "." + rawBody`, keyed by the UTF-8 bytes of the connection key. During a key rotation the header carries one
 * `v1=` per live key; any matching value is accepted. The window is |now − t| ≤ tolerance (300 s).
 *
 * This module is crypto-agnostic so the same code runs in Node (node:crypto), in a browser (WebCrypto) and inside
 * an n8n node: the caller injects the HMAC primitive. Nothing here reads the environment, logs, or throws.
 */
/**
 * Parses the header. Like the kit verifiers it splits on commas and reads `t=` and `v1=` pairs; it is stricter in
 * two places that cannot change a valid delivery's verdict: `t` must be 1 to 13 digits (the kit accepts any digit
 * run) and a `v1` value must be 64 hex characters (the kit keeps any text and fails the comparison instead).
 * Returns null when there is no usable `t` or no well-formed `v1`.
 */
export function parseSignatureHeader(header) {
    if (typeof header !== 'string')
        return null;
    const text = header.trim();
    if (text.length === 0 || text.length > 8192)
        return null;
    let t = null;
    const v1 = [];
    for (const part of text.split(',')) {
        const eq = part.indexOf('=');
        if (eq < 0)
            continue;
        const key = part.slice(0, eq).trim();
        const value = part.slice(eq + 1).trim();
        if (key === 't') {
            if (!/^\d{1,13}$/.test(value))
                return null;
            t = Number(value);
        }
        else if (key === 'v1') {
            if (/^[0-9a-fA-F]{64}$/.test(value))
                v1.push(value.toLowerCase());
        }
    }
    if (t === null || v1.length === 0)
        return null;
    return { t, v1 };
}
const encoder = new TextEncoder();
/** The exact bytes that are signed: UTF-8 of `${t}.` followed by the raw request body. */
export function signedPayload(t, rawBody) {
    const prefix = encoder.encode(`${t}.`);
    const out = new Uint8Array(prefix.length + rawBody.length);
    out.set(prefix, 0);
    out.set(rawBody, prefix.length);
    return out;
}
/**
 * The keys a receiver can check with: strings only, trimmed (the phone trims a key before it stores and signs with
 * it, so a key pasted with a trailing newline must still verify), empty ones dropped. Each entry keeps the index it
 * had in the caller's array so `keyIndex` means something to the caller.
 */
export function usableKeys(keys) {
    if (!Array.isArray(keys))
        return [];
    const out = [];
    keys.forEach((value, index) => {
        if (typeof value !== 'string')
            return;
        const key = value.trim();
        if (key.length > 0)
            out.push({ key, index });
    });
    return out;
}
/** A window in seconds; anything that is not a finite non-negative number falls back to the contract's 300. */
export function toleranceOf(value) {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : 300;
}
/** Pure-JS constant-time comparison of two lower-case hex strings of equal length. */
export function constantTimeEqualHex(a, b) {
    if (a.length !== b.length || a.length === 0)
        return false;
    let diff = 0;
    for (let i = 0; i < a.length; i++)
        diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    return diff === 0;
}
/** Everything that does not need the HMAC: key presence, raw body, header shape and the time window. */
function precheck(input) {
    const keys = usableKeys(input.keys);
    if (keys.length === 0)
        return { ok: false, result: { ok: false, reason: 'no_key', t: null } };
    if (!(input.rawBody instanceof Uint8Array))
        return { ok: false, result: { ok: false, reason: 'raw_body_unavailable', t: null } };
    if (typeof input.header !== 'string' || input.header.trim().length === 0)
        return { ok: false, result: { ok: false, reason: 'missing_header', t: null } };
    const parsed = parseSignatureHeader(input.header);
    if (parsed === null)
        return { ok: false, result: { ok: false, reason: 'malformed_header', t: null } };
    const tolerance = toleranceOf(input.toleranceSec);
    if (typeof input.now !== 'number' || !Number.isFinite(input.now) || Math.abs(input.now - parsed.t) > tolerance) {
        return { ok: false, result: { ok: false, reason: 'stale_timestamp', t: parsed.t } };
    }
    return { ok: true, parsed, keys };
}
function compare(parsed, expected, equalHex) {
    for (const { hex, index } of expected) {
        for (const candidate of parsed.v1) {
            if (equalHex(candidate, hex))
                return { ok: true, t: parsed.t, keyIndex: index };
        }
    }
    return { ok: false, reason: 'bad_signature', t: parsed.t };
}
/** Verifies a delivery with synchronous primitives. Never throws. */
export function verifySignature(input, hmac) {
    const pre = precheck(input);
    if (!pre.ok)
        return pre.result;
    const payload = signedPayload(pre.parsed.t, input.rawBody);
    const expected = pre.keys.map(({ key, index }) => ({ hex: hmac.hmacSha256Hex(key, payload).toLowerCase(), index }));
    return compare(pre.parsed, expected, hmac.equalHex ?? constantTimeEqualHex);
}
/** The same check with asynchronous primitives (WebCrypto). Never rejects. */
export async function verifySignatureAsync(input, hmac) {
    const pre = precheck(input);
    if (!pre.ok)
        return pre.result;
    const payload = signedPayload(pre.parsed.t, input.rawBody);
    const expected = [];
    for (const { key, index } of pre.keys)
        expected.push({ hex: (await hmac.hmacSha256Hex(key, payload)).toLowerCase(), index });
    return compare(pre.parsed, expected, hmac.equalHex ?? constantTimeEqualHex);
}
/** Builds the header value for a body, as the phone does (one `v1=` per key, current first). For tests and tools. */
export function signatureHeader(t, rawBody, keys, hmac) {
    const payload = signedPayload(t, rawBody);
    return `t=${t},` + usableKeys(keys).map(({ key }) => `v1=${hmac.hmacSha256Hex(key, payload)}`).join(',');
}
