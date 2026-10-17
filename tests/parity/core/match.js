/**
 * Payer-and-time matching, ported from kit/reference/match.mjs as a pure function: the caller passes the transfer
 * and the payer's candidate orders, gets back the merchant reply and the action to apply to the store.
 *
 * Rules (the contract, docs/02 §E.8):
 * - A transfer is recognised by the payer the customer registered at checkout, inside the order's time window;
 *   the amount only decides between that payer's own open orders (*9# transfers are whole pounds).
 * - "Time" is when the money arrived (`received_at`), never when the delivery reached the receiver. Candidates are
 *   the payer's orders that did not expire more than `late_window` before the money and were not created more than
 *   `skew` after it; a transfer never pays an order opened after the money arrived.
 * - A late delivery (money older than the late window) with no such order is `review`, not `unmatched`.
 * - `auto_confirm_ok` false is always `review`.
 *
 * Deliberate differences from the reference, each on the safe side: a transfer without an integer amount is
 * `review` (the reference would compare a missing amount as 0); an order of this payer whose price or times cannot
 * be read is never matched, and its existence turns a miss into `review` so a human looks instead of the transfer
 * being called `unmatched`.
 */
import { normalizePayer, payerKeyOf } from './payer.js';
import { isSuspectedForgery } from './envelope.js';
const toMs = (value) => {
    if (value instanceof Date)
        return Number.isFinite(value.getTime()) ? value.getTime() : null;
    if (typeof value === 'number')
        return Number.isFinite(value) ? value : null;
    if (typeof value === 'string') {
        const parsed = Date.parse(value);
        return Number.isFinite(parsed) ? parsed : null;
    }
    return null;
};
const minutes = (value, fallback) => typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value * 60_000 : fallback * 60_000;
const isRecord = (v) => typeof v === 'object' && v !== null && !Array.isArray(v);
/** Reads the fields the matcher needs from either shape: raw `data` of transfer.received, or the summarised `transfer`. */
function readTransfer(input) {
    if (!isRecord(input))
        return null;
    const payer = isRecord(input['payer']) ? input['payer'] : null;
    const counterparty = isRecord(input['counterparty']) ? input['counterparty'] : null;
    const payerKey = (typeof payer?.['key'] === 'string' ? normalizePayer(payer['key']) : null) ?? payerKeyOf(counterparty) ?? payerKeyOf(payer);
    return {
        payerKey,
        amountMinor: Number.isInteger(input['amount_minor']) ? input['amount_minor'] : null,
        receivedAt: input['received_at'],
        autoOk: input['auto_confirm_ok'] === true,
        transferId: typeof input['transfer_id'] === 'string' ? input['transfer_id'] : null,
        txnId: typeof input['provider_txn_id'] === 'string' ? input['provider_txn_id'] : null,
    };
}
/** An order of this payer as the matcher can use it, or null when its price or times cannot be read. */
function readOrder(value, payerKey) {
    if (!isRecord(value))
        return { order: null, samePayer: false };
    const o = value;
    const key = typeof o.payer_key === 'string' ? (normalizePayer(o.payer_key) ?? o.payer_key) : null;
    if (key !== payerKey)
        return { order: null, samePayer: false };
    const open = o.status === undefined || o.status === 'open' || o.status === 'partial';
    if (!open)
        return { order: null, samePayer: false };
    const created = toMs(o.created_at);
    const expires = toMs(o.expires_at);
    const price = Number.isInteger(o.price_minor) && o.price_minor >= 0 ? o.price_minor : null;
    const paid = Number.isInteger(o.paid_minor) && o.paid_minor >= 0 ? o.paid_minor : o.paid_minor === undefined || o.paid_minor === null ? 0 : null;
    if (typeof o.order_number !== 'string' || created === null || expires === null || price === null || paid === null)
        return { order: null, samePayer: true };
    return { order: { o, created, expires, price, paid }, samePayer: true };
}
/** Matches one transfer.received against the candidate orders. Pure: nothing is mutated, nothing throws. */
export function matchTransfer(transfer, orders, options = {}) {
    const t = readTransfer(transfer);
    const base = { order_number: null, action: null, amount_minor: t?.amountMinor ?? null, transfer_id: t?.transferId ?? null, provider_txn_id: t?.txnId ?? null, payer_key: t?.payerKey ?? null, stop_order: false };
    if (!t)
        return { status: 'review', note: 'no transfer data', ...base };
    if (!t.autoOk)
        return { status: 'review', ...base }; // risk != low → a human looks
    if (t.amountMinor === null || t.amountMinor < 0)
        return { status: 'review', note: 'no amount in the message', ...base };
    if (!t.payerKey)
        return { status: 'unmatched', note: 'no payer identifier in the message', ...base };
    const lateMs = minutes(options.late_window_min, 120);
    const skewMs = minutes(options.skew_min, 10);
    const now = typeof options.now === 'number' && Number.isFinite(options.now) ? options.now : Date.now();
    const got = toMs(t.receivedAt);
    const at = got !== null && got < now ? got : now; // when the money arrived; a clock ahead counts as "now"
    const stale = now - at > lateMs; // a retry, a manual resend or a backfill
    let unreadable = 0;
    const candidates = [];
    for (const value of Array.isArray(orders) ? orders : []) {
        const { order, samePayer } = readOrder(value, t.payerKey);
        if (order)
            candidates.push(order);
        else if (samePayer)
            unreadable++;
    }
    const inWindow = candidates
        .filter((c) => c.expires >= at - lateMs) // incl. just-expired
        .filter((c) => c.created <= at + skewMs) // never an order opened after the money arrived
        .sort((a, b) => b.created - a.created); // newest first
    if (inWindow.length === 0) {
        if (unreadable > 0)
            return { status: 'review', note: `${unreadable} order(s) of this payer could not be read (price or times)`, ...base };
        return stale
            ? { status: 'review', note: 'late delivery: no order of this payer was open when the money arrived', ...base }
            : { status: 'unmatched', ...base };
    }
    const amount = t.amountMinor;
    const exact = inWindow.filter((c) => c.price - c.paid === amount);
    const chosen = exact[0] ?? (inWindow.length === 1 && unreadable === 0 ? inWindow[0] : undefined); // amount decides, else the only one
    if (!chosen)
        return { status: 'review', note: unreadable > 0 ? 'another order of this payer could not be read' : 'several open orders for this payer, none matches the amount', ...base };
    const remaining = chosen.price - chosen.paid;
    const found = { ...base, order_number: chosen.o.order_number, reference: chosen.o.order_number };
    if (amount < remaining)
        return { ...found, status: 'partial', action: 'mark_partial' };
    if (chosen.expires < at)
        return { ...found, status: 'matched', action: 'mark_paid', note: 'paid after expiry' };
    return { ...found, status: amount > remaining ? 'overpaid' : 'matched', action: 'mark_paid' };
}
/** Judges `data` of a transfer.updated (or a summarised transfer). Never throws. */
export function judgeUpdate(data) {
    const record = isRecord(data) ? data : {};
    const transferId = typeof record['transfer_id'] === 'string' ? record['transfer_id'] : null;
    const forged = isSuspectedForgery(record) || record['suspected_forgery'] === true || record['balance_chain'] === 'refuted';
    return { status: 'ok', transfer_id: transferId, stop_order: forged, reason: forged ? 'suspected_forgery' : null };
}
