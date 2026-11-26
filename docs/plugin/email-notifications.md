# Email notifications

The plugin sends four kinds of email.

## To the payer

- **Payment created** — sent when a payment is first generated, with the QR code and payment details attached.
- **Status changed** — sent whenever a payment's status actually changes — see [Payment statuses](/plugin/payment-statuses) for the full list (requested, partially paid, paid, overpaid, expired, cancelled, refunded).
- **Payment reminder** — sent to the payer 7 days before a payment of this type is due, and again the day it becomes overdue.

## To your team

- **Payments summary** — a digest of every payment of a given type that's still requested, expired, partially paid, or marked paid-by-payer-but-unconfirmed, sent to whichever users are configured as recipients on that payment type. Unlike the others, nothing in the plugin decides *when* this goes out — it only runs when something calls `php craft qr-payments/reminder/send`, which means a cron job (or your host's scheduled tasks) needs to be set up to run it on whatever interval you want.

## Customizing the wording

All four render the plugin's own bundled templates by default. Each payment type has its own `paymentCreatedTemplate` / `paymentStatusChangedTemplate` / `paymentReminderTemplate` / `paymentsSummaryTemplate` setting — point one at a template in your own site to override it for that type without touching the plugin.
