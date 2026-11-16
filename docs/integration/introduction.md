# Introduction

Beyond the control panel, this plugin exposes a small set of anonymous, public endpoints so your own site templates can request a payment on a visitor's behalf and show them the result — a checkout confirmation page, an order summary, a donation form — without ever touching the control panel.

There is no payment processor and no webhooks: once a payment is requested, it sits **requested** until the site owner reconciles it by hand from the bank statement (see [Payment statuses](/plugin/payment-statuses)). The integration surface only covers *requesting* a payment and *checking* its current status — never confirming it.

This guide covers, in order:

1. [Requesting a payment](/integration/requesting-a-payment) — posting an amount and email to create a payment.
2. [The pay page](/integration/the-pay-page) — the bundled "scan to pay" page a request redirects to.
3. [Checking payment status](/integration/checking-payment-status) — looking a payment's current status up from your own template.
