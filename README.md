# QR Payments

EPC QR code SEPA payment requests for Craft CMS — with easy management built right in to the control panel, no payment processor required.

You generate a standard [EPC QR code](https://en.wikipedia.org/wiki/EPC_QR_code) straight from your own IBAN. A customer scans it with their own banking app and transfers the money directly to your account. There's no gateway, no merchant account, no fees, and no webhooks — you confirm each payment yourself from the control panel (one at a time, or in bulk by uploading a bank export).

## Is this plugin for you?

**Good fit if:**

- Your clients bank in the EEA/SEPA area and want to accept transfers without a payment processor's fees or setup.
- "Pending until someone checks the bank statement" is an acceptable confirmation flow — donations, pre-orders, invoices, deposits, informal bookings.
- You want payments to behave like normal, queryable Craft elements inside the CP you already use.
- You're processing more than a handful of payments a week and want bulk bank-export reconciliation instead of checking each one by hand.

**Not a fit if:**

- You need instant, automatic confirmation (e.g. to unlock content or ship an order the moment payment clears) — nothing here is instant; a human has to check the bank account.
- Your payers are outside SEPA, or need to pay by card — this is bank-transfer-via-QR only, not a card/wallet gateway.
- You need automated refunds — the plugin only _records_ a refund; moving the money back is still up to you in your banking app.
- You want a drop-in checkout widget — you bring your own form/template; the plugin provides the request endpoint, QR generation, and bookkeeping.

## Requirements

- Craft CMS 5.10.0+
- PHP 8.4+

## Supported countries

The EPC QR standard covers the 36 countries/territories in the SEPA zone. Within the EEA, EU regulation lets a transfer go through on IBAN alone, so BIC is optional there — everywhere else in SEPA, BIC is required.

**BIC not required** (EU/EEA):

Austria, Belgium, Bulgaria, Croatia, Cyprus, Czechia, Denmark, Estonia, Finland, France, Germany, Greece, Hungary, Iceland, Ireland, Italy, Latvia, Liechtenstein, Lithuania, Luxembourg, Malta, Netherlands, Norway, Poland, Portugal, Romania, Slovakia, Slovenia, Spain, Sweden

**BIC required** (SEPA, non-EEA):

Andorra, Monaco, San Marino, Switzerland, United Kingdom, Vatican City

## Installation

```bash
# Plugin Store
# Search "QR Payments" in your project's Control Panel → Plugin Store → Install

# Composer
cd /path/to/my-project.test
composer require digitalastronaut/craft-qr-payments
php craft plugin/install qr-payments
```

## Quick start: your first payment form

**1. Configure the beneficiary.** Go to **QR Payments → Settings → General** and fill in the account the QR code should point to:

| Setting          | Required             |
| ---------------- | -------------------- |
| Beneficiary name | Yes                  |
| IBAN             | Yes                  |
| BIC              | Only outside the EEA |

Name, IBAN and BIC all accept `$ENV_VAR` references, so the real values can live in `.env`.

**2. Add a request form to any template.** The amount is hashed server-side so a payer can't tamper with it in devtools:

```twig
<form method="post" action="">
    {{ csrfInput() }}
    {{ actionInput('qr-payments/payments/request') }}

    <input type="hidden" name="amount" value="{{ craft.app.security.hashData('10.00') }}">

    <label>
        <span>Email</span>
        <input type="email" name="email" required>
    </label>

    <button type="submit">Pay €10</button>
</form>
```

**3. That's it.** Submitting redirects the payer to the plugin's bundled "scan to pay" page — QR code, amount, beneficiary details. The payment shows up in **QR Payments → Payment history** as `Pending`.

**4. Get paid, then mark it.** Once you see the transfer land in your bank account, open the payment (or the **Process payments** bulk screen) and click **Mark as paid**.

## Core concepts

### Payments are elements

Every payment is a first-class Craft element — searchable, filterable by status, sortable, with its own field layout. Add custom fields (order reference, internal notes, a linked entry) under **Settings → Fields** / **Settings → Elements → Payment**.

### Status is computed, not set

A payment's status is derived from an append-only status history, so partial payments, overpayments and refunds fall out automatically instead of needing manual bookkeeping:

| Status         | Meaning                                                               |
| -------------- | --------------------------------------------------------------------- |
| Pending        | Nothing paid, due date not passed.                                    |
| Partially paid | Something paid, less than the full amount.                            |
| Paid           | Full amount paid.                                                     |
| Overpaid       | More than the full amount paid.                                       |
| Expired        | Still pending once the due date passes.                               |
| Cancelled      | Manually cancelled — final, regardless of anything paid before/after. |
| Refunded       | Nothing currently paid, but a refund is on record.                    |

Four actions drive every transition: **Mark as paid**, **Add payment** (partial), **Refund**, **Cancel** — available from a payment's edit page or in bulk from Process payments.

### Checking status from Twig

No dedicated JSON endpoint — a payment is a normal element, queried through the plugin's variable:

```twig
{% set payment = craft.qrPayments.payments({ uid: uid }).status(null).one() %}

{% if payment %}
    {{ payment.getStatus() }} {# always use getStatus(), not .status — it accounts for expiry #}
    {{ payment.getAmount() }}
{% endif %}
```

`.status(null)` is required — element queries only return _enabled_ elements by default, which isn't the same thing as payment status. To reflect status changes without a page reload, wrap the same query in a small controller action of your own and poll it from the front end.

### Bulk reconciliation

**QR Payments → Process payments** accepts a bank export (CSV, any delimiter, or `.xlsx`/`.xls`) as-is — no column mapping required in advance. It auto-detects the reference, amount and date columns, matches rows against your payments, and suggests an action per row (`Add payment → Partially paid`, `Mark as paid → Paid`, …). **Auto process** applies every suggestion in one click. Re-uploading an overlapping export is safe — already-applied transactions are fingerprinted and skipped rather than double-recorded.

### Email notifications

Three system emails, each overridable with your own site template via Settings → Emails:

| Email            | Sent to   | When                                   |
| ---------------- | --------- | -------------------------------------- |
| Payment created  | Payer     | A payment is first generated.          |
| Status changed   | Payer     | Any status transition.                 |
| Payment reminder | Your team | On a schedule you control — see below. |

The reminder digest has no built-in scheduler; wire a cron job to:

```bash
php craft qr-payments/reminder/send
```

### Subscriptions

A subscription is a recurrence schedule, not a gateway integration: it stamps out the next `Payment` on a cadence, same as any other payment, for you to reconcile manually. Advancing has no built-in scheduler either; wire a cron job to:

```bash
php craft qr-payments/subscription/advance
```

Run it at least daily. Each run advances every active subscription whose `nextDueDate` has arrived, creating that cycle's payment and pushing `nextDueDate` forward by the subscription's interval.

### Customizing the pay page

Point **Settings → Payment page → Pay page template** at your own site template instead of the bundled one. The redirect target (`/qr-payments/payments/<uid>/pay`, or a route you've renamed under **Pay page route**) stays the same — your template just receives the same `payment` element and a ready-to-use `qrCodeDataUri`.

### Translations

Every user-facing string goes through `Craft::t('qr-payments', ...)` / `|t('qr-payments')`. Add a locale by creating `translations/{locale}/qr-payments.php` as a flat array of source strings mapped to translations.

## Full documentation

This README covers the decision and the first form. For everything else — settings reference, screenshots, console commands, matching-column internals — see the [full docs site](https://github.com/digitalastronaut-be/craft-qr-payments).

## License

Proprietary. Built by [digitalastronaut](https://digitalastronaut.be).
