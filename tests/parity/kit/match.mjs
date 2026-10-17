// Reference merchant-side matching (≈ 70 lines): when the customer chooses "pay by wallet", ask
// for the wallet number (or InstaPay handle) they will pay from and store it with the order. A
// transfer is then recognised by that payer inside the order's time window; the amount only decides
// between that customer's own open orders. Any SQL store works; see orders.sql.
//
// "Time" is when the money arrived (data.received_at), never when the request reached you. A delivery
// is often later than the money:
//  - up to 15 minutes on a healthy phone, longer if the phone is asleep: an incoming transfer whose
//    stated balance does not follow the wallet's balance, or that arrives while Qersh does not know
//    the wallet's balance yet (the first transfer on a new wallet), is held while the merchant checks
//    the real balance in the wallet app;
//  - hours or weeks after an outage: retries run for at least 7 days (one after another behind the
//    oldest waiting delivery, so weeks after a long outage), the merchant can resend by hand,
//    and what the phone kept while Qersh was not activated or the subscription was stopped is sent
//    up to 30 days later (header X-Qersh-Redelivery: backfill, same delivery_id and created_at, with
//    the newest verdict: a balance check that settled meanwhile is already in it).
// So a transfer is matched against the orders that were open when the money arrived, and never pays
// an order opened afterwards (a customer who opens a second order while the first payment is held
// must not have the first payment moved to it).
//
// Why payer + time, not amounts: wallet transfers made with the *9# code are whole pounds, so two
// customers paying 150 at the same minute carry no distinguishing piasters, but each of them told
// you which number the money comes from.
//
// What the message carries about the payer depends on the provider: Vodafone Cash and e& money
// print the number (counterparty.number), the InstaPay app a handle like name@instapay
// (counterparty.handle), Orange Cash and bank SMS only a name plus the transaction id. For the
// name-only providers ask the customer to type the operator transaction id at checkout and match
// provider_txn_id first (as Qersh's own checkout does), else put the transfer in review.
// Keep the wallet's "Send sender details" on "Full name and number" (the default): with "masked"
// the number arrives as 0111***3445 and matches nothing.
//
// A later transfer.updated (same transfer_id) can say the message was a suspected forgery
// (risk_reasons contains suspected_forgery, checks.balance_chain is refuted). Then stop the order
// that transfer paid: nothing ships until a human sees the money in the wallet app. See updated().

const LATE_MS = 2 * 60 * 60_000; // an order that expired this recently before the money still accepts it
const SKEW_MS = 10 * 60_000;     // the phone's clock and the store's may differ by minutes

export class OrderMatcher {
  constructor(store) { this.store = store; } // store: see the interface at the bottom

  /** Open an order for wallet payment: what the customer owes (whole pounds are fine) and the payer they will use. */
  open(orderNumber, priceMinor, payer, ttlMinutes = 120) {
    const payerKey = normalizePayer(payer);
    if (!payerKey) throw new Error('the customer must give the wallet number or InstaPay handle they will pay from');
    const createdAt = Date.now();
    this.store.saveOpenOrder({ orderNumber, priceMinor, paidMinor: 0, payerKey, createdAt, expiresAt: createdAt + ttlMinutes * 60_000 });
  }

  /** Called from the webhook handler with event.data of transfer.received. Returns the merchant reply. */
  match(data) {
    if (data.auto_confirm_ok !== true) return { status: 'review' };              // risk != low → a human looks
    const amount = data.amount_minor;                                             // integer piasters, compare as integers
    const payerKey = normalizePayer(data.counterparty?.number) ?? normalizePayer(data.counterparty?.handle);
    if (!payerKey) return { status: 'unmatched', note: 'no payer identifier in the message' };

    const now = Date.now();
    const got = Date.parse(data.received_at ?? '');
    const at = Number.isFinite(got) && got < now ? got : now;                    // when the money arrived; a clock ahead counts as "now"
    const stale = now - at > LATE_MS;                                            // a retry, a manual resend or a backfill
    const candidates = this.store.findOpenByPayer(payerKey, at - LATE_MS)       // newest first, incl. just-expired
      .filter((o) => o.createdAt <= at + SKEW_MS);                               // never an order opened after the money arrived
    if (candidates.length === 0) return stale ? { status: 'review', note: 'late delivery: no order of this payer was open when the money arrived' } : { status: 'unmatched' };
    const exact = candidates.filter((o) => o.priceMinor - o.paidMinor === amount);
    const order = exact[0] ?? (candidates.length === 1 ? candidates[0] : null);  // amount decides, else the only one
    if (!order) return { status: 'review', note: 'several open orders for this payer, none matches the amount' };

    const remaining = order.priceMinor - order.paidMinor;
    if (amount < remaining) { this.store.markPartial(order.orderNumber, data.provider_txn_id, amount, data.transfer_id); return { status: 'partial', reference: order.orderNumber }; }
    this.store.markPaid(order.orderNumber, data.provider_txn_id, amount, data.transfer_id);
    if (order.expiresAt < at) return { status: 'matched', reference: order.orderNumber, note: 'paid after expiry' };
    return { status: amount > remaining ? 'overpaid' : 'matched', reference: order.orderNumber };
  }

  /** Called with event.data of transfer.updated. Always answered "ok"; a forgery label stops the order that transfer paid. */
  updated(data) {
    const reasons = Array.isArray(data.risk_reasons) ? data.risk_reasons : [];
    if (reasons.includes('suspected_forgery') || data.checks?.balance_chain === 'refuted') {
      this.store.flagTransfer(data.transfer_id, 'suspected_forgery');            // stop it: a human checks the wallet before anything ships
    }
    return { status: 'ok' };
  }
}

/**
 * `01xxxxxxxxx` from any spelling (+20 1…, +20 01…, 0020…, spaces, Arabic digits), or an InstaPay
 * handle as the app delivers it: `name@instapay` lower-case (a bare `name` gets the suffix). Null otherwise.
 */
export function normalizePayer(raw) {
  const s = String(raw ?? '').trim().toLowerCase();
  if (!s) return null;
  if (s.includes('@')) return /^[a-z0-9._-]{2,64}@instapay$/.test(s) ? s : null;
  let d = '';
  for (const ch of s) {
    const c = ch.charCodeAt(0);
    if (c >= 0x30 && c <= 0x39) d += ch;
    else if (c >= 0x660 && c <= 0x669) d += String.fromCharCode(c - 0x660 + 0x30);
    else if (c >= 0x6f0 && c <= 0x6f9) d += String.fromCharCode(c - 0x6f0 + 0x30);
  }
  if (d.startsWith('00')) d = d.slice(2);                       // 0020…
  if (d.startsWith('20') && d.length >= 12) d = d.slice(2);     // 201… or 2001…
  if (d.length === 10 && d.startsWith('1')) d = '0' + d;        // 1xxxxxxxxx
  if (/^01[0-9]{9}$/.test(d)) return d;
  if (/^[a-z0-9._-]{2,64}$/.test(s) && /[a-z]/.test(s)) return s + '@instapay'; // handle typed without the suffix
  return null;
}

/* Store interface (implement over your database; orders.sql has matching tables):
   saveOpenOrder({orderNumber, priceMinor, paidMinor, payerKey, createdAt, expiresAt})   times in epoch ms
   findOpenByPayer(payerKey, notExpiredBefore) → order[]   open/partial orders of this payer whose expires_at ≥ notExpiredBefore, newest first;
                                                           every order carries createdAt (the money is checked against it)
   markPaid(orderNumber, txnId, amountMinor, transferId)   markPartial(orderNumber, txnId, amountMinor, transferId)
                                                           keep transferId with the payment: transfer.updated names nothing else
   flagTransfer(transferId, reason)                        the order paid by this transfer goes back to review and must not ship;
                                                           a transfer that paid no order is only remembered for the human who reviews it
   Keep the wallet's "send counterparty" setting on "full" in the Qersh app, or counterparty.number never arrives.
   Remember: also store delivery_id → reply for at least 180 days (or as long as the order exists) and answer repeats
   with the same reply (the same delivery can come again months later: a backfill of what the phone kept, or a manual
   resend of a delivery that failed for good). */
