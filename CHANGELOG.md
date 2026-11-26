# Release Notes for QR payments

## 1.10.0-beta - 2026-11-19
- Added `Subscription::getStatusHtml()`/`Payment::getStatusHtml()` — one ready-to-render `<div class="[status]">...</div>` (`<div class="none">No subscription</div>`, `<div class="active">Valid subscription (12 days left)</div>`, `<div class="paid">Paid</div>`, ...) instead of a template or site service re-deriving the same markup from `getStatus()`/`isValid()`/`isPending()` with its own if/else. A raw `cancelled` `Subscription` reads back as `none`/"No subscription" too, same as never having subscribed — not a status a subscriber should see as its own thing. Fires a new `EVENT_DEFINE_STATUS_HTML` (Craft's own `craft\events\DefineHtmlEvent`) after computing this plugin's own default, so a site can override the markup and wording entirely — or add detail this plugin has no way to know about — by listening for it instead of forking either element.

## 1.9.2-beta - 2026-11-17
- Added `QrPaymentsVariable::getEligiblePaymentTypes(handles = [], user = null)`/`getEligibleSubscriptionTypes(...)` — every type `user` is allowed to request (via `PaymentType`/`SubscriptionType::isRequestableBy()`), optionally restricted to an ordered candidate-handle list, `|first`-able down to one. `$user` defaults to whoever's logged in; `$handles` defaults to every configured type of that kind. Lets a project resolve "which of *these specific* types applies to this user" (when its types aren't all interchangeable — some access-gated, some open) in one call, instead of mapping handles to types and filtering itself in every template that needs it.
- Added `Subscription::latestFor(?User $user, SubscriptionType $type)` (static) + `QrPaymentsVariable::subscription(user, type)` — the user's own most recent `Subscription` of a type, meant to be chained off directly from a template rather than asked as separate questions: `craft.qrPayments.subscription(user, type).isValid()`, `.validUntil()`, `.isPending()`. Added the matching `Subscription::isValid()`/`validUntil()`/`isPending()` instance methods. Replaces the previous `QrPaymentsVariable::isValid()`/`validUntil()` (removed) and the ad hoc "pending" element queries — the status logic now lives on the element itself instead of being asked about it externally.
- `latestFor()`/`subscription()` now never return `null` — a user (or guest) with no matching subscription at all gets a transient, never-saved `Subscription` instance back instead, whose `isValid()`/`isPending()` correctly read `false` and `validUntil()`/`getLatestPayment()` read `null`. Lets a template chain straight off `craft.qrPayments.subscription(user, type).isValid()` with no null-guard of its own, including under Twig's `strict_variables` (which otherwise throws on calling a method on a `null` value, unlike plain attribute access).

## 1.9.1-beta - 2026-11-17
- Added `QrPaymentsVariable::paymentType(handle)`/`subscriptionType(handle)` — the model-level counterpart to the existing `payments()`/`subscriptions()` element queries, for templates that need the type itself (its `amount`, `interval`, `isRequestableBy()`, etc.) rather than a list of its elements. Lets a project resolve which of its own types a user may request, and read everything about it, entirely from Twig.

## 1.9.0-beta - 2026-11-17
- Added `requestableBy` to both `PaymentType` and `SubscriptionType` — a checkbox list of "Guest" plus every user group, restricting who can request that type through `PaymentsController`/`SubscriptionsController::actionRequest()`. Empty (the default for every existing type) means unrestricted. Enforced automatically by both actions before anything is created — no event listener needed for simple group-gating.
- Added `PaymentTypesService`/`SubscriptionTypesService::getRequestableTypesFor(?User $user)`, filtering every type by the new `isRequestableBy()`.
- Added `Subscription::isValidFor(?User $user, SubscriptionType $type)`/`validUntilFor(...)` (static) — whether/until-when a user currently has a non-expired `active` `Subscription` of a given type.
- Added `QrPaymentsVariable::subscriptions()` (mirroring the existing `payments()`), plus `isValid()`/`validUntil()` wrappers over the new `Subscription` statics — so a project's own Twig can query either element type, or check a subscriber's standing, with no PHP of its own.

## 1.8.0-beta - 2026-11-17
- `PaymentsController::actionRequest()`/`SubscriptionsController::actionRequest()` now return `asSuccess()`/`asFailure()` instead of a raw HTTP redirect, so a JS `fetch` gets back JSON (with a `redirect` key) instead of the browser silently following a real redirect.
- Both `actionRequest()` actions now accept an optional signed `payer` token (`craft.qrPayments.requestToken(userId, typeHandle)`), letting a site request a payment/subscription *on behalf of* someone else entirely — e.g. an agent requesting a license for an artist they manage — without trusting a plain posted `userId`. The token bundles the target user, the type, and an issue time into one HMAC-signed value (`RequestTokensService`), rejected once older than an hour, so a leaked or stale token can't be replayed indefinitely.
- `PaymentsController::actionRequest()` now also accepts a plain `typeHandle` body param (mirroring `SubscriptionsController::actionRequest()`'s existing `subscriptionTypeHandle`), instead of always using the default `PaymentType`.
- Added a fixed, optional `amount` to `PaymentType` (mirroring `SubscriptionType::$amount`) — a payment type with one configured needs no posted/hashed amount at all when requested.
- A subscriber can no longer end up with more than one `requested`/`active` `Subscription` of the same `SubscriptionType` — enforced both as a new `Subscription::validateNotDuplicate()` validation rule (every save path) and as an early check in `SubscriptionsService::requestSubscription()` (throwing a dedicated `DuplicateSubscriptionException` for a clean message). Keyed on `userId` when there is one, else `email` compared case-insensitively.
- Added `PaymentsController::EVENT_BEFORE_REQUEST_PAYMENT`/`SubscriptionsController::EVENT_BEFORE_REQUEST_SUBSCRIPTION`, fired before a requested payment/subscription is actually created — lets a site enforce its own eligibility rules (who's allowed to request what) with one event listener instead of a parallel controller.

## 1.7.0-beta - 2026-11-14
- Added **payment types**: every payment now belongs to a `PaymentType`, each with its own field layout, beneficiary/IBAN/BIC override, reference prefix, and email/pay-page template overrides. One seeded "Default" type keeps existing installs working unchanged. New **Payment types** CP screen (admin-only).
- Added **subscriptions**: a `Subscription` is a recurrence schedule, a real element (like `Payment`) with its own native, paginated, filterable CP index and element query. "New subscription" creates an unpublished draft and uses the same native edit screen as every other save, the same way a new Entry works — no bespoke creation form. A new `qr-payments/subscription/advance` console command (run at least daily, same pattern as the existing reminder cron) advances every active, due subscription, stamping out and linking the next `Payment` and pushing its `nextDueDate` forward by its interval. No gateway integration — this only ever creates the next payment for manual reconciliation, same as a one-off payment.
- Added **subscription types**: price and interval live on a shared `SubscriptionType` ("plan"), not on each individual `Subscription` — every subscriber references one instead of duplicating its amount/interval. A subscriber can still freeze their own rate with a per-subscription override; changing a plan's price otherwise applies to every non-overridden subscriber on their very next cycle. New **Subscription types** (admin-only) and **Subscriptions** CP screens.
- A subscription's owner is a nullable Craft user account *and* an email snapshot, not either/or — guest subscribers (no front-end account) and access-gated ones (keyed off a real user) are both supported from the same table.
- Fixed: creating a payment whose type's custom fields included a Matrix or CKEditor field could crash while queuing its "payment created" email (`Serialization of 'Closure' is not allowed`) — a lazily-cached field layout was riding along into the job's serialized state. Affected any payment of such a type, not just subscriptions.
- The interval dropdowns (a subscription type's own cadence, and a subscriber's per-subscription override) are now searchable `selectize` fields, matching the rest of the CP. Added `daily`, `biweekly` (bi-weekly), and `quarterly` alongside the existing weekly/monthly/yearly options.

## 1.6.0-beta - 2026-11-13
- Added a new `awaiting_confirmation` payment status, sitting between `pending` and `paid`. The public pay page now offers an "I've paid" button (shown while a payment is `pending` or `partial`) that lets the payer flag their own transfer as sent; the site owner still has to verify and confirm it manually from the control panel, same as before. Self-reports are recorded as a new `payer_reported` status history entry, shown in the payment's status history alongside the existing payment/refund/cancellation entries.
- Moved the "Ingredients calculator" dashboard widget out of this plugin into the site's `site` module — it's bakery recipe/product logic, not QR payment logic, and only read `Payment` elements as its data source.
- Merged the settings page's "Payment page" tab into "General", renaming its "Pay page route" field to "Payment page route" — the page only had the two tabs and didn't need them.

## 1.5.1-beta - 2026-11-05
- Fixed: `confirmedAt`/`confirmedByUserId` were never actually stamped — the 1.4.0-beta changelog entry for this got ahead of the code, and the fields sat unused ever since. A payment reaching `paid` now stamps both from whichever status history entry completed the amount (whether that was a manual "Mark as paid"/"Add payment", or an entry applied from a bank statement import), and clears them again if a refund or cancellation later moves the status away from `paid`. Also now shown on the payment's sidebar info.

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
