# Email notifications

The plugin sends three kinds of email.

## To the payer

- **Payment created** — sent when a payment is first generated, with the QR code and payment details attached.
- **Status changed** — sent whenever a payment's status actually changes — see [Payment statuses](/plugin/payment-statuses) for the full list (pending, partially paid, paid, overpaid, expired, cancelled, refunded).

## To your team

- **Payment reminder** — a digest of every payment still pending, expired, or partially paid, sent to whichever users are configured as recipients under the Notifications tab in [Settings](/plugin/settings). Unlike the two above, nothing in the plugin decides *when* this goes out — it only runs when something calls `php craft qr-payments/reminder/send`, which means a cron job (or your host's scheduled tasks) needs to be set up to run it on whatever interval you want.

## Customizing the wording

All three render the plugin's own bundled templates by default. Point `paymentCreatedTemplate` / `paymentStatusChangedTemplate` / `paymentReminderTemplate` in [Settings](/plugin/settings) at a template in your own site to override any of them without touching the plugin.
