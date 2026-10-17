/**
 * The setup link the merchant pastes into "Add wallet" in the Qersh app (the app calls it "your developer's link"):
 * `qersh://setup?v=1&url=<urlencoded>[&secret=<key>][&label=<text>][&provider=<id>][&events=<csv>]`.
 *
 * Mirrors the app's reader (android `SetupLink.kt` and `WebhookUrl`): the URL must be https, or http only for a
 * private host; the label is cut to 60 characters; `events` may list only the names the app knows (an unknown name
 * makes the app refuse the whole link; listing events switches off every event not listed); a key, when present,
 * must be usable. A link without a key makes the phone generate the key itself, which keeps the key out of every
 * system but the phone and the receiver.
 */
import { isAcceptableKey } from './config.js';
/** Event names the app accepts in a link's `events` list. webhook.test is always sent and cannot be listed. */
export const SETUP_LINK_EVENTS = [
    'transfer.received',
    'transfer.sent',
    'transfer.failed',
    'transfer.updated',
    'message.unparsed',
    'device.heartbeat',
    'subscription.limit_reached',
];
/** Wallet type ids (kit/webhook.v1.schema.json `wallet.provider`). The app accepts other values but flags them. */
export const SETUP_LINK_PROVIDERS = [
    'vodafone_cash',
    'etisalat_cash',
    'orange_cash',
    'instapay_app',
    'bank_egbank',
    'bank_nbe',
    'bank_cib',
    'bank_kfh',
];
export const SETUP_LINK_LABEL_MAX = 60;
export const SETUP_LINK_URL_MAX = 2048;
/** The app's `WebhookUrl.PRIVATE_HOST`: loopback, RFC 1918 and a single-label `.local` name. No IPv6. */
const PRIVATE_HOST = /^(localhost|127\.\d+\.\d+\.\d+|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+|[a-z0-9-]+\.local)$/i;
/** Hosts for which the app allows plain http (developer mode). */
export function isLocalHttpHost(hostname) {
    return PRIVATE_HOST.test(hostname);
}
/**
 * The app's rule for a key inside a link (`SetupLink.isUsableSecret`): the pasted-key rule (24+ characters, no white
 * space), not a URL, and a key that starts like a Qersh key is made only of the characters such a key has.
 */
export function isUsableSecret(secret) {
    if (!isAcceptableKey(secret))
        return false;
    const s = secret.trim();
    if (s.includes('://'))
        return false;
    return !s.startsWith('qsk_') || /^qsk_[A-Za-z0-9_-]+$/.test(s);
}
/** Validates a receiver URL the way the app does for a link (`WebhookUrl.isValid(url, allowInsecure = true)`). */
export function validateReceiverUrl(url) {
    if (typeof url !== 'string' || url.trim().length === 0)
        return { ok: false, problem: 'url_missing' };
    const text = url.trim();
    if (text.length > SETUP_LINK_URL_MAX)
        return { ok: false, problem: 'url_too_long' };
    let parsed;
    try {
        parsed = new URL(text);
    }
    catch {
        return { ok: false, problem: 'url_invalid' };
    }
    // Android reports user info for a bare "@" too, which the WHATWG parser swallows: look at the text.
    if (parsed.username || parsed.password || /^[a-z][a-z0-9+.-]*:\/\/[^/?#]*@/i.test(text))
        return { ok: false, problem: 'url_userinfo' };
    if (parsed.hostname.length === 0)
        return { ok: false, problem: 'url_invalid' };
    if (parsed.protocol === 'https:')
        return { ok: true };
    if (parsed.protocol === 'http:')
        return isLocalHttpHost(parsed.hostname) ? { ok: true } : { ok: false, problem: 'url_insecure_remote' };
    return { ok: false, problem: 'url_scheme' };
}
/** Cleans up the names in an `events` list the way the reader does: trimmed, empty ones dropped. */
function eventNames(events) {
    return (Array.isArray(events) ? events : []).map((e) => (typeof e === 'string' ? e.trim() : '')).filter((e) => e.length > 0);
}
/** Checks the parameters against the app's reader rules. Never throws. */
export function validateSetupLink(params) {
    const url = validateReceiverUrl(params.url);
    if (!url.ok)
        return { ok: false, problem: url.problem };
    const warnings = [];
    if (typeof params.secret === 'string' && params.secret.trim() !== '') {
        if (!isUsableSecret(params.secret))
            return { ok: false, problem: 'secret_invalid' };
        warnings.push('secret_included');
    }
    else if (params.secret !== undefined && params.secret !== null && params.secret !== '') {
        return { ok: false, problem: 'secret_invalid' };
    }
    for (const e of eventNames(params.events)) {
        if (!SETUP_LINK_EVENTS.includes(e))
            return { ok: false, problem: 'event_unknown', detail: e };
    }
    if (typeof params.label === 'string' && params.label.trim().length > SETUP_LINK_LABEL_MAX)
        warnings.push('label_truncated');
    if (typeof params.provider === 'string' && params.provider.trim() !== '' && !SETUP_LINK_PROVIDERS.includes(params.provider.trim().toLowerCase()))
        warnings.push('provider_unknown');
    return { ok: true, warnings };
}
/** The label as the app keeps it: trimmed, cut to 60 UTF-16 units, without a surrogate half the cut may have left. */
function cutLabel(label) {
    if (typeof label !== 'string')
        return '';
    let s = label.trim().slice(0, SETUP_LINK_LABEL_MAX);
    const last = s.charCodeAt(s.length - 1);
    if (last >= 0xd800 && last <= 0xdbff)
        s = s.slice(0, -1);
    return s;
}
const percent = (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase().padStart(2, '0');
/**
 * Percent-encodes a link parameter like encodeURIComponent, plus the characters `!'()*` it leaves raw (a `'` is a
 * chat wrapper for the app's reader and `)` is trailing punctuation). Lone surrogates are replaced first, so this
 * never throws. Everything else, including `.`, stays as kit/sink/send.mjs prints it.
 */
export function encodeLinkParam(value) {
    const safe = value.replace(/[\ud800-\udbff](?![\udc00-\udfff])|(?<![\ud800-\udbff])[\udc00-\udfff]/g, '�');
    return encodeURIComponent(safe).replace(/[!'()*]/g, percent);
}
/**
 * Builds the link. Validate first with {@link validateSetupLink}; this function only shapes what it is given (label
 * cut to 60 characters, provider lower-cased, empty parts omitted). The output equals kit/sink/send.mjs
 * `--setup-link` for ordinary values; it differs only where the app's reader would otherwise eat a character:
 * `!'()*` are percent-encoded, and so is a final `. , ) ; : ?` (sentence punctuation the reader drops from the end).
 */
export function buildSetupLink(params) {
    let link = 'qersh://setup?v=1&url=' + encodeLinkParam(typeof params.url === 'string' ? params.url.trim() : '');
    if (typeof params.secret === 'string' && params.secret.trim() !== '')
        link += '&secret=' + encodeLinkParam(params.secret.trim());
    const label = cutLabel(params.label);
    if (label)
        link += '&label=' + encodeLinkParam(label);
    const provider = typeof params.provider === 'string' ? params.provider.trim().toLowerCase() : '';
    if (provider)
        link += '&provider=' + encodeLinkParam(provider);
    const events = eventNames(params.events);
    if (events.length > 0)
        link += '&events=' + encodeLinkParam(events.join(','));
    const last = link.charAt(link.length - 1);
    if (TRAILING.has(last))
        link = link.slice(0, -1) + percent(last);
    return link;
}
// The app's extraction rules (SetupLink.kt): a link ends at white space, at a bidi or zero-width mark and at any
// character that is never raw in an encoded link; sentence punctuation right after it is dropped; a chat wrapper
// (*bold*, _italic_, ~strike~, 'quoted') that opened right before the link is dropped from its end.
const LINK_RX = /qersh:\/\/setup\?[^\s\u200B\u200E\u200F\u202A-\u202E\u2066-\u2069\uFEFF`"\u201C\u201D\u2018\u2019\u00AB\u00BB<>{}[\]|\\^\u060C\u061B\u061F\u2026]+/i;
const TRAILING = new Set(['.', ',', ')', ';', ':', '?']);
const WRAPPERS = new Set(['*', '_', '~', "'"]);
const trimTrailing = (s) => {
    let end = s.length;
    while (end > 0 && TRAILING.has(s.charAt(end - 1)))
        end--;
    return s.slice(0, end);
};
/** The setup link inside any text a chat app or the clipboard hands over, or null. Same rules as the app. */
export function extractSetupLink(text) {
    if (typeof text !== 'string')
        return null;
    const match = LINK_RX.exec(text);
    if (!match)
        return null;
    let link = trimTrailing(match[0]);
    const before = match.index > 0 ? text.charAt(match.index - 1) : '';
    if (before && WRAPPERS.has(before) && link.endsWith(before))
        link = trimTrailing(link.slice(0, -1));
    return link;
}
/** Reads a link back (for tests, tools and the website generator). Applies the same rules as the app's reader. */
export function parseSetupLink(text) {
    const link = extractSetupLink(text);
    if (link === null)
        return { ok: false, problem: 'not_a_setup_link' };
    let query;
    try {
        query = new URLSearchParams(link.slice(link.indexOf('?') + 1));
    }
    catch {
        return { ok: false, problem: 'not_a_setup_link' };
    }
    const v = query.get('v');
    if (v !== null && v !== '1')
        return { ok: false, problem: 'bad_version' };
    const url = (query.get('url') ?? '').trim();
    const urlCheck = validateReceiverUrl(url);
    if (!urlCheck.ok)
        return { ok: false, problem: urlCheck.problem };
    const secretRaw = (query.get('secret') ?? '').trim();
    if (secretRaw !== '' && !isUsableSecret(secretRaw))
        return { ok: false, problem: 'secret_invalid' };
    const events = eventNames((query.get('events') ?? '').split(','));
    for (const e of events)
        if (!SETUP_LINK_EVENTS.includes(e))
            return { ok: false, problem: 'event_unknown' };
    const label = cutLabel(query.get('label'));
    const provider = (query.get('provider') ?? '').trim().toLowerCase();
    return {
        ok: true,
        link: { url, secret: secretRaw || null, label: label || null, provider: provider || null, events: events.length > 0 ? events : null },
    };
}
