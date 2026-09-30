# Release Notes for QR payments

## 1.5.0-beta - 2026-09-29
- Added a payment reminder digest email, listing every payment still awaiting processing, on a configurable frequency (none/daily/weekly/every two weeks) sent to a chosen set of CP users — new "Reminders" settings tab, `qr-payments/emails/_paymentReminder` template (overridable like the others), and a `qr-payments/reminder/send` console command meant to be invoked by an external cron on at least a daily basis.

## 1.4.0-beta - 2026-09-25
- Rebuilt the bank export import screen around a drag and drop, map, then process flow. The CSV is parsed in the browser the moment it's dropped, with no upload and no page reload.
- Added a column mapping step: two dropdowns list every header in the file alongside an example value, preselected by name where the headers give it away.
- Added a processing view that walks the statement one line at a time, showing the CSV row beside its matched payment, flagging amounts that don't line up, and letting the payment's status be changed inline. Everything still to do is listed in a table below.
- References are now read out of messy statement text (padded with spaces, wrapped in markers, or buried in a description) by checksum testing every candidate against the payments that exist, instead of scraping the raw file with a regex.
- Amounts are read from any of the formats bank exports use (`1.234,56`, `1,234.56`, `€ 12,50`, `-12,50`).
- Moving a payment to `paid` now stamps `confirmedAt` and `confirmedByUserId`.
- Replaced `ReconciliationService` with `ImportService`, and moved the import routes onto a dedicated `ImportController`.

## 1.3.0-beta - 2026-09-25
- Replaced the `Due & expired` element index source with one source per payment status (Pending, Paid, Incorrect amount, Expired, Cancelled, Refunded), grouped under a "Betaalstatussen" heading above `Recent`/`History`.

## 1.2.0-beta - 2026-09-25
- Replaced `NotificationsService` with a shared `SendTemplatedEmailJob` base class — each email is now a self-contained queue job (`SendPaymentCreatedEmailJob`, `SendPaymentStatusChangedEmailJob`) that resolves its own recipient/subject/template/variables, instead of a service both jobs delegated to.

## 1.1.0-beta - 2026-09-25
- Added a “Pay page route” setting so the public pay/status page URL doesn’t have to expose the `qr-payments/payments` plugin handle in production. Overridable per environment via `config/qr-payments.php`.

## 1.0.0
- Initial release
