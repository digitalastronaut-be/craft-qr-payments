# Settings

**QR Payments → Settings** (admin only) is split into five tabs.

<!-- ![The plugin's settings page, showing the General tab with the beneficiary name/IBAN/BIC fields and the tab bar (General, Payment fields, Payment page, Notifications, Emails) above them](../public/images/plugin-settings.png) -->
_Screenshot: the settings page, General tab, with the tab bar visible along the top._

## General

Every payment's EPC QR code is stamped with the beneficiary details configured here.

| Setting | Description |
| --- | --- |
| Plugin name | Label shown in the control panel nav. |
| Beneficiary name | Account holder name printed on the QR code. Required. |
| IBAN | Destination account for every generated payment. Required. |
| BIC | Optional within the EEA; required for non-EEA accounts. |
| Reference prefix | Prepended to the auto-generated remittance reference on each payment. Only letters and digits are kept — anything else is stripped. |
| Default expiry time | Days after creation a new payment is due, when no explicit due date is given. Administrative only — nothing enforces it on the payer's side. |

### Environment variables

`Beneficiary name`, `IBAN` and `BIC` all accept `$ENV_VAR` references (autosuggest included) so the real values can live in `.env` instead of project config.

## Payment fields

A field layout designer for the **Payment** element — the same extra fields described in [Creating a payment](/plugin/creating-a-payment).

## Payment page

| Setting | Description |
| --- | --- |
| Pay page route | The site route segment the public pay page is served under (e.g. `betalen` instead of the default `qr-payments/payments`). |
| Pay page template | A site template path to render instead of the plugin's bundled pay page — see [The pay page](/integration/the-pay-page). Leave blank to use the default. |

## Notifications

Configures the **payment reminder** digest — see [Email notifications](/plugin/email-notifications) for what it actually sends. The plugin has no scheduler of its own: this tab only sets *who* receives it, not *when*. A cron job (or your host's scheduled tasks) needs to run:

```bash
php craft qr-payments/reminder/send
```

on whatever interval you want reminders to go out — daily is typical.

| Setting | Description |
| --- | --- |
| Recipients | The users who should receive the reminder digest. |

## Emails

Each of the plugin's three system emails renders its own bundled template by default — override any of them with a site template path instead:

| Setting | Overrides |
| --- | --- |
| Payment created email template | The "payment created" email. |
| Status changed email template | The "status changed" email. |
| Payment reminder email template | The reminder digest email. |
