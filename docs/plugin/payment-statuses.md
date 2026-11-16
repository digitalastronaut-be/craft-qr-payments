# Payment statuses

There's no payment processor behind this plugin, so nothing moves a payment between statuses automatically except expiry — every other transition is a deliberate action taken from the control panel once you've checked your bank account, either one payment at a time here or in bulk from [Processing payments](/plugin/processing-payments).

A payment's status is never set directly — it's always computed from its **status history**, an append-only log of every payment, refund and cancellation recorded against it. That's what makes partial payments, overpayments and refunds all fall out correctly on their own instead of needing separate manual bookkeeping.

| Status | Meaning |
| --- | --- |
| Requested | Created, nothing paid yet, due date not passed. |
| Partially paid | Something's been paid, but less than the full amount. |
| Paid | The full amount has been paid. |
| Overpaid | More than the full amount has been paid. |
| Expired | Still requested once its due date has passed. |
| Cancelled | Manually cancelled. Sticks regardless of anything paid before or after — see below. |
| Refunded | Nothing currently paid, but a refund is on record (so it was paid at some point). |

## Recording what happened

A payment's edit page (and each row in [Processing payments](/plugin/processing-payments)) shows up to four buttons, depending on its current state:

- **Mark as paid** — records the full outstanding amount in one entry. Only offered while nothing's been paid yet.
- **Add payment** — records a specific amount, via a small form. Use this for partial payments, or to record any amount at all outside the "haven't paid anything yet" case Mark as paid covers.
- **Refund** — records money paid back to the payer. Only offered once something has actually been paid, and can't exceed what's currently paid (net of previous refunds).
- **Cancel** — cancels the payment. Once cancelled, only Refund remains available (in case something had already been paid) — Cancelled is final and doesn't change with later payment/refund entries.

<!-- ![The action buttons on a payment's edit page, and its status history table below them showing a payment, a status pill, an amount and a note](../public/images/payment-status-history.png) -->
_Screenshot: a payment's edit screen sidebar with the action buttons, and the status history table underneath showing a couple of entries (status, date, updated by, amount, note)._

Every recorded entry shows up in the status history table with its status, date, who recorded it, the amount, and an optional note.

## Reconciling payments one at a time

Use the remittance reference printed on the QR code (and shown on the payment) to find the matching transfer in your bank statement, then use the buttons above from the payment's own edit page or the **QR Payments → Payment history** index. For anything beyond the occasional payment, [Processing payments](/plugin/processing-payments) does this same matching automatically across an entire bank export.
