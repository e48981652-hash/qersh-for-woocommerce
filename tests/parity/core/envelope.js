/**
 * The qersh.v1 envelope: parsing from the raw body (never from a re-serialised object) and the normalised item a
 * receiver hands to its workflow. Field names follow kit/webhook.v1.schema.json; readers ignore unknown fields.
 */
import { payerKeyOf } from './payer.js';
const decoder = new TextDecoder('utf-8');
const isRecord = (v) => typeof v === 'object' && v !== null && !Array.isArray(v);
/**
 * Parses the raw body into an envelope. Call it only after the signature verified. Requires the fields every
 * qersh.v1 delivery carries (`schema`, `event`, `delivery_id`, `created_at`, `wallet`, `data`). Never throws.
 */
export function parseEnvelope(rawBody) {
    let text;
    if (typeof rawBody === 'string')
        text = rawBody;
    else if (rawBody instanceof Uint8Array)
        text = decoder.decode(rawBody);
    else
        return { ok: false, reason: 'invalid_json' };
    let value;
    try {
        value = JSON.parse(text.charCodeAt(0) === 0xfeff ? text.slice(1) : text);
    }
    catch {
        return { ok: false, reason: 'invalid_json' };
    }
    if (!isRecord(value))
        return { ok: false, reason: 'not_an_object' };
    const wallet = value['wallet'];
    if (value['schema'] !== 'qersh.v1' ||
        typeof value['event'] !== 'string' ||
        typeof value['delivery_id'] !== 'string' ||
        typeof value['created_at'] !== 'string' ||
        !isRecord(wallet) ||
        typeof wallet['id'] !== 'string' ||
        typeof wallet['provider'] !== 'string' ||
        !isRecord(value['data'])) {
        return { ok: false, reason: 'not_qersh_v1' };
    }
    return { ok: true, envelope: value };
}
/** Reads the `X-Qersh-*` headers from any header object (names are matched case-insensitively). Never throws. */
export function deliveryHeaders(headers) {
    const source = isRecord(headers) ? headers : {};
    const get = (name) => {
        const direct = source[name] ?? source[name.toLowerCase()];
        const raw = direct ?? Object.entries(source).find(([k]) => k.toLowerCase() === name.toLowerCase())?.[1];
        if (Array.isArray(raw))
            return typeof raw[0] === 'string' ? raw[0] : null;
        return typeof raw === 'string' ? raw : null;
    };
    const attemptText = get('x-qersh-attempt');
    const redelivery = get('x-qersh-redelivery');
    const signature = get('x-qersh-signature');
    const tMatch = signature ? /(?:^|,)\s*t=(\d+)\s*(?:,|$)/.exec(signature) : null;
    const tText = tMatch?.[1];
    return {
        delivery: get('x-qersh-delivery'),
        attempt: attemptText !== null && /^\d{1,9}$/.test(attemptText) ? Number(attemptText) : null,
        redelivery: redelivery === 'manual' || redelivery === 'backfill' ? redelivery : null,
        wallet: get('x-qersh-wallet'),
        signature_t: tText !== undefined && tText.length <= 13 ? Number(tText) : null,
    };
}
/** True when a transfer or an update carries the forgery verdict of the balance check. */
export function isSuspectedForgery(data) {
    if (!isRecord(data))
        return false;
    const reasons = Array.isArray(data['risk_reasons']) ? data['risk_reasons'] : [];
    const checks = isRecord(data['checks']) ? data['checks'] : undefined;
    return reasons.includes('suspected_forgery') || checks?.['balance_chain'] === 'refuted';
}
const str = (v) => (typeof v === 'string' ? v : null);
const int = (v) => (Number.isInteger(v) ? v : null);
const bool = (v) => (typeof v === 'boolean' ? v : null);
/** Summarises `data` of a transfer event, of webhook.test's sample, or of transfer.updated (then most fields are null). */
export function summarizeTransfer(data) {
    if (!isRecord(data))
        return null;
    const cp = isRecord(data['counterparty']) ? data['counterparty'] : null;
    const checks = isRecord(data['checks']) ? data['checks'] : undefined;
    return {
        transfer_id: str(data['transfer_id']),
        direction: str(data['direction']),
        status: str(data['status']),
        amount: str(data['amount']),
        amount_minor: int(data['amount_minor']),
        currency: str(data['currency']),
        received_at: str(data['received_at']),
        payer: {
            number: str(cp?.number),
            handle: str(cp?.handle),
            name: str(cp?.name),
            key: payerKeyOf(cp),
        },
        provider_txn_id: str(data['provider_txn_id']),
        balance_after: str(data['balance_after']),
        risk: str(data['risk']),
        risk_reasons: Array.isArray(data['risk_reasons']) ? data['risk_reasons'].filter((r) => typeof r === 'string') : [],
        auto_confirm_ok: bool(data['auto_confirm_ok']),
        balance_chain: str(checks?.['balance_chain']),
        suspected_forgery: isSuspectedForgery(data),
    };
}
/** Builds the normalised item from a parsed envelope. `webhookUrl` is the receiver's production URL (no key), or null. */
export function summarize(envelope, headers, webhookUrl) {
    const event = typeof envelope.event === 'string' ? envelope.event : '';
    const data = isRecord(envelope.data) ? envelope.data : {};
    let transfer = null;
    if (event.startsWith('transfer.'))
        transfer = summarizeTransfer(data);
    else if (event === 'webhook.test') {
        const sample = data['sample'];
        transfer = isRecord(sample) ? summarizeTransfer(sample) : null;
    }
    return {
        event,
        delivery_id: envelope.delivery_id,
        attempt: headers?.attempt ?? null,
        redelivery: headers?.redelivery ?? null,
        test: envelope.test === true || event === 'webhook.test',
        created_at: envelope.created_at,
        wallet: envelope.wallet,
        transfer,
        data,
        envelope,
        webhook_url: typeof webhookUrl === 'string' && webhookUrl.length > 0 ? webhookUrl : null,
    };
}
