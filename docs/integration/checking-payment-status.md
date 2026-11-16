# Checking payment status

There's no dedicated JSON status-check endpoint — a payment is a normal Craft element, so the plugin's own Twig variable is the way to look one up from a template:

```twig
{% set payment = craft.qrPayments.payments({ uid: uid }).status(null).one() %}

{% if payment %}
    {{ payment.getStatus() }}
{% endif %}
```

A few things worth calling out:

- `.status(null)` is needed because element queries only return *enabled* elements by default, and a payment's enabled state isn't the same thing as its [payment status](/plugin/payment-statuses) — without it, a cancelled payment (for example) might not come back at all.
- Call `.getStatus()`, not `.status` — the plain `status` property is whatever was last saved, but `getStatus()` also accounts for a requested payment's due date having passed since then (returning `expired` without needing a fresh save first). Always prefer the method.
- `payment.getAmount()`, `.remittanceReference`, `.dueDate` and the rest of the element's normal attributes are all available the same way.

## Without a full page reload

Since this only works from a Twig template, showing a status change without the payer refreshing means building a small endpoint of your own around the same query — a plugin-provided one doesn't exist yet. It's a short controller action: look up the payment the same way as above, return `{ status: payment.getStatus() }` as JSON, and poll it from the front end.
