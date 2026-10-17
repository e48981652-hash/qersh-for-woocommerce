/**
 * The Standard Webhooks headers every Qersh delivery also carries: `webhook-id`, `webhook-timestamp`,
 * `webhook-signature: v1,<base64 HMAC-SHA256>` over `id.timestamp.body`, keyed by the UTF-8 bytes of the connection
 * key (the official `standardwebhooks` libraries expect that key base64-encoded; here it is used directly).
 * `X-Qersh-Signature` stays the primary header; this verifier exists for receivers that already speak the standard.
 */
import { toleranceOf, usableKeys } from './signature.js';
const encoder = new TextEncoder();
function hexToBase64(hex) {
    let binary = '';
    for (let i = 0; i < hex.length; i += 2)
        binary += String.fromCharCode(parseInt(hex.slice(i, i + 2), 16));
    return btoa(binary);
}
function constantTimeEqual(a, b) {
    if (a.length !== b.length || a.length === 0)
        return false;
    let diff = 0;
    for (let i = 0; i < a.length; i++)
        diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    return diff === 0;
}
/** Verifies the Standard Webhooks signature. Never throws. */
export function verifyStandardWebhooks(input, hmac) {
    const keys = usableKeys(input.keys);
    if (keys.length === 0)
        return { ok: false, reason: 'no_key' };
    if (!(input.rawBody instanceof Uint8Array))
        return { ok: false, reason: 'raw_body_unavailable' };
    if (typeof input.id !== 'string' || typeof input.timestamp !== 'string' || typeof input.signature !== 'string')
        return { ok: false, reason: 'missing_header' };
    const timestamp = input.timestamp.trim();
    if (!/^\d{1,13}$/.test(timestamp))
        return { ok: false, reason: 'malformed_header' };
    const ts = Number(timestamp);
    if (typeof input.now !== 'number' || !Number.isFinite(input.now) || Math.abs(input.now - ts) > toleranceOf(input.toleranceSec))
        return { ok: false, reason: 'stale_timestamp' };
    const candidates = input.signature
        .split(' ')
        .map((part) => part.trim())
        .filter((part) => part.startsWith('v1,'))
        .map((part) => part.slice(3));
    if (candidates.length === 0)
        return { ok: false, reason: 'malformed_header' };
    const prefix = encoder.encode(`${input.id}.${timestamp}.`);
    const payload = new Uint8Array(prefix.length + input.rawBody.length);
    payload.set(prefix, 0);
    payload.set(input.rawBody, prefix.length);
    for (const { key, index } of keys) {
        const expected = hexToBase64(hmac.hmacSha256Hex(key, payload));
        for (const candidate of candidates)
            if (constantTimeEqual(candidate, expected))
                return { ok: true, keyIndex: index };
    }
    return { ok: false, reason: 'bad_signature' };
}
