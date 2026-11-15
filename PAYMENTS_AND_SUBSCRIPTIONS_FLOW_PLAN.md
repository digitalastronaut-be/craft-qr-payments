# Payments & Subscriptions Flow Review

Analysis of the current `Payment`/`Subscription` status models, services, and
email flows, against the desired end-state flows, with conclusions and a
phased plan for closing the gaps. No code has been changed as part of this
document — it's the planning pass requested before touching anything.

## 1. Payments flow — essentially done

Walking the desired flow against the actual code:

1. **Request** → `PaymentsController::actionRequest()` / `PaymentsService::createPayment()` creates a `Payment` in `STATUS_PENDING` (`'pending'`, labeled "Pending"). Matches, modulo the rename below.
2. **Marked as paid (optional, payer self-report)** → `actionReportPaid()` pushes a `payer_reported` history entry, `computeStatus()` resolves that to `STATUS_AWAITING_CONFIRMATION` (`'reported'`, labeled "Marked paid"). Matches.
3. **Paid, correct amount; admin checks** → admin "Add payment"/"Mark as paid" buttons push a `payment` history entry; `computeStatus()` resolves `STATUS_PAID` once `netPaidAmount()` equals `amount`. Matches.
4. **Over/under-paid; admin checks** → same `computeStatus()` match arm resolves `STATUS_OVERPAID`/`STATUS_PARTIAL` automatically. Matches.
5. **Never pays, expires** → `Payment::getStatus()` and `PaymentQuery::statusCondition()`/`effectiveStatusExpression()` both compute `STATUS_EXPIRED` virtually (pending + `dueDate` passed) rather than writing it to the DB — well engineered, stays consistent between in-PHP and query-level checks with no cron needed to "flip" it. `ReminderController` sends the payer a nudge at due-7d and again at due+1d (first day expired). Matches.
6. **Admin cancels** → a `cancellation` history entry resolves `STATUS_CANCELLED` via the "Cancel" button. Matches.

**Conclusion:** the payments flow needs no structural work. The only concrete
follow-up is the status-1 rename, which is cheaper than it first looks:

- Renaming just the **label** (`statuses()`'s `'Pending' → 'Requested'`) is a
  one-line, zero-risk change — ships instantly.
- Renaming the **stored value** (`STATUS_PENDING = 'pending'`) is a much
  bigger, riskier change: `'pending'` is a literal written into every
  historical `qr_payments_payments.status` row, and is hardcoded into
  `PaymentQuery::effectiveStatusExpression()`'s raw SQL (`:pendingStatus`/
  `:pendingLabel` bind params), `computeStatus()`, `canReportPaidByPayer()`,
  element sources, `SendPaymentsSummaryEmailJob`'s status filter, and
  translations. **Recommendation: rename the label only, keep the internal
  value `pending`.**

## 2. Subscriptions flow — the real work

Walking the 5 points from the brief against the actual code:

### 2.1 "A user requests a subscription (we need a requested status), a related payment is automatically created"

**Gap — nothing like this exists yet.**

- `SubscriptionsController` requires `qr-payments:manage-subscriptions` on
  *every* action, including `actionIndex()` — there is no public/anonymous
  route at all (contrast `PaymentsController`, which allows anonymous
  `request`/`pay`/`report-paid`). A subscription can only be created by an
  admin, via the CP's native "New subscription" unpublished-draft flow.
- `Subscription::statuses()` only defines `active`/`paused`/`cancelled` — no
  `requested` state.
- Creating a subscription does **not** create a first `Payment`.
  `SubscriptionsService::advance()` — the only code path that stamps out a
  `Payment` — is only ever invoked by the daily `qr-payments/subscription/advance`
  cron, and only for subscriptions whose `nextDueDate` has already arrived
  (`getDueSubscriptions()`). A freshly created subscription has
  `nextDueDate = null` (nothing defaults it), so it's invisible to the cron
  until an admin manually fills that field in — which
  `SubscriptionFieldsField::nextDueDateFieldHtml()`'s own instructions warn
  about ("Left blank, the recurrence runner never picks this subscription up").

**Plan:** add `Subscription::STATUS_REQUESTED`; add a public, anonymous
controller action that creates a `requested` subscription and immediately
generates its first linked `Payment` (reusing the payment-creation half of
`advance()`). The subscription stays `requested` until that payment is paid.

### 2.2 "Once paid → subscription active; visualize next payment status on the element index"

**Gap — no wiring between a `Payment` and its parent `Subscription`'s status.**

- `Payment::afterSave()`/`notifyStatusChange()` never inspects
  `subscriptionId`. `SubscriptionsService::activate()` exists but is only
  ever called from the admin-only `SubscriptionsController::actionActivate()`
  — nothing triggers it from a payment event.
- `Subscription::defineTableAttributes()`/`attributeHtml()` has no "next
  payment" column — the element index shows plan/amount/interval/status/
  `nextDueDate` but nothing about the payment that's actually outstanding.

**Plan:** in `Payment::afterSave()` (or a new event hook), when a saved
payment's `subscriptionId` is set, its status just became `paid`, and the
owning `Subscription` is still `requested`, call
`SubscriptionsService::activate()` and stamp `startedAt`. For **renewal**
payments on an already-`active` subscription, paid status just confirms the
cycle — no status change needed (see open question on paused/lapsed
renewals, §4.2). Add a `getLatestPayment()`/`getNextPayment()` helper on
`Subscription` and a `nextPayment` table attribute rendering a status pill +
due date (same rendering idiom `Payment::attributeHtml('historyActions')`
already uses).

### 2.3 "A subscription can be paused by the admin for any reason"

**Already implemented** — `SubscriptionsService::pause()` /
`actionPause()`. No gap.

### 2.4 "A subscription can be cancelled by the user for any reason (stays valid until the next cycle)"

**Gap — both the grace period and the self-service surface are missing.**

- `SubscriptionsService::cancel()` sets `status = cancelled` and
  `endedAt = now()` **immediately** — there's no "valid until end of current
  cycle" grace period.
- There is no user-facing cancel action at all — same gap as §2.1,
  `SubscriptionsController` is fully admin-gated.
- Even if a grace-period flag existed today, `SubscriptionController::actionAdvance()`
  (the cron) doesn't check for a pending cancellation before calling
  `advance()` — it would still stamp out another renewal payment for a
  subscription that's supposed to stop at period end.

**Plan:** this needs a state distinct from "cancel now" (admin), e.g. a
`cancelAtPeriodEnd` flag or a `STATUS_CANCELLING`/"ends on {nextDueDate}"
state, set by a new public action, which `getDueSubscriptions()`/`advance()`
checks and — once the cycle arrives — finalizes into `cancelled` instead of
creating another payment.

### 2.5 "Renewal timing: configurable lead time; handle paying before/after cycle start"

**Gap — "when to request payment" and "when the cycle starts" are the same
moment today, and there's no lead-time setting anywhere.**

- `advance()` always creates the renewal payment exactly when
  `nextDueDate` arrives (`getDueSubscriptions()` matches `nextDueDate <= now`),
  then immediately pushes `nextDueDate` forward by one interval. There's no
  separate "cycle start" vs. "payment requested" date, and no per-
  `SubscriptionType` (or per-`Subscription`) lead-time field — nothing
  mirrors `PaymentType::$defaultDueDateDays`'s pattern for subscriptions.
- **Paying early** (before the cycle starts) already works fine — nothing
  gates "access" on payment timing, since the plugin has no access-control
  feature at all; a `Payment` just sits `pending` until paid, whenever that is.
- **Paying late** (after the cycle has started) isn't modeled either — a
  late/unpaid renewal payment just becomes `expired` on the `Payment` side
  like any one-off payment. Nothing on the `Subscription` reacts to that: it
  doesn't auto-pause, doesn't flag as "at risk."

**Plan:** add a `renewalLeadDays`-style setting on `SubscriptionType`
(mirroring `PaymentType::$defaultDueDateDays`), so "create the renewal
payment N days before `nextDueDate`" is configurable. This requires
separating "the date the next payment should be *requested*"
(`nextDueDate - renewalLeadDays`) from "the date the next cycle actually
*starts*" (`nextDueDate`, unchanged) — likely via a second date column (e.g.
keep `nextDueDate` as the cycle boundary, add something like
`nextPaymentRequestedAt` for the cron to key off). What should happen to a
subscription whose renewal payment is still unpaid once the new cycle has
already started is an open decision (§4.2) — not yet answered by the brief.

## 3. Emails — inventory and gaps

| Trigger | Job | Template | Recipient | Per-type override |
|---|---|---|---|---|
| First save of any `Payment` (standalone or subscription-generated) | `SendPaymentCreatedEmailJob` | `_paymentCreated.twig` | payment's notification email | `PaymentType::$paymentCreatedTemplate` |
| Any real (DB-persisted) status transition after the first save | `SendPaymentStatusChangedEmailJob` | `_statusChanged.twig` | payment's notification email | `PaymentType::$paymentStatusChangedTemplate` |
| Daily cron, exact due-7d and due+1d (first expired day) match | `SendPaymentReminderEmailJob` | `_paymentReminder.twig` | payer | `PaymentType::$paymentReminderTemplate` |
| Daily cron, per `PaymentType` with recipients + ≥1 payment needing action | `SendPaymentsSummaryEmailJob` | `_paymentsSummary.twig` | admin recipients configured on the type | `PaymentType::$paymentsSummaryTemplate` |
| *(none)* | — | — | — | `Subscription`/`SubscriptionType` status changes send no email at all |

Two things worth flagging while re-planning these:

- `_paymentReminder.twig`'s "due in 7 days" copy is a hardcoded literal
  inside an `|t('qr-payments')` string, not interpolated from a setting. If
  §2.5's lead-time becomes configurable, this copy needs to read the actual
  number instead of always saying "7 days."
- **`SubscriptionType` has no template-override fields at all** — unlike
  `PaymentType`, which has five (`payTemplate`, `paymentCreatedTemplate`,
  `paymentStatusChangedTemplate`, `paymentsSummaryTemplate`,
  `paymentReminderTemplate`). Any new subscription lifecycle email needs
  that capability added first if per-type overrides are wanted (consistent
  with how every payment email already works).

**Once §2.1/§2.2/§2.4 land, these subscription lifecycle emails are needed:**

- "Your subscription request has been received" — likely just the existing
  `SendPaymentCreatedEmailJob` on the auto-created first payment, possibly
  with subscription-flavored copy, rather than a new job.
- "Your subscription is now active" — on activation (§2.2).
- "Your subscription has been paused" — admin-initiated; decide if the
  subscriber should be told (§4.3).
- "Your subscription will end on {date}" — confirmation of a user-initiated
  cancel-at-period-end (§2.4).
- "Your subscription has ended" — once a pending cancellation finalizes.
- Possibly a distinct renewal pre-notice ("your plan renews in {N} days") if
  the lead time (§2.5) ends up being more than a few days.

## 4. Open questions — need answers before building

1. **Rename scope:** label only ("Pending" → "Requested" display text,
   recommended), or the stored value too (requires a data migration)?
2. **Late/unpaid renewal behavior:** should it auto-pause the subscription,
   or just sit as an `expired` `Payment` with reminders firing, leaving the
   `Subscription` status untouched until an admin intervenes?
3. **Cancel-at-period-end scope:** user-initiated only, or can an admin also
   choose "cancel at period end" as an alternative to the existing immediate
   cancel?
4. **Paused subscriptions and the renewal cron:** already correct —
   `getDueSubscriptions()` filters `->status(Subscription::STATUS_ACTIVE)`,
   so a paused subscription is never advanced. No change needed; listed here
   only to confirm it doesn't need revisiting.
5. **Lead-time scope:** one global plugin setting, or per `SubscriptionType`
   (recommended, for consistency with `PaymentType::$defaultDueDateDays`)?
6. **Who can self-cancel:** any visitor with a lookup token/URL (the way the
   public pay page keys off `Payment::$uid`), or only an authenticated Craft
   user account? This determines whether a guest subscriber (no `userId`)
   can self-cancel at all, and whether `Subscription::hasUris()` needs to
   flip to `true`.

## 5. Proposed phased plan

**Phase 1 — Payment status label rename** *(low risk)*
1. Rename `Payment::statuses()`'s `STATUS_PENDING` label from "Pending" to
   "Requested." Keep the constant/stored value `pending` unchanged.
2. Sweep translations and any Twig copy for other "pending"-flavored
   wording that should follow the label (confirmed: no other hardcoded
   literal needs it today, besides the label itself).

**Phase 2 — Subscription request flow**
1. Add `Subscription::STATUS_REQUESTED`.
2. New public, anonymous controller action (new controller, or a relaxed
   `allowAnonymous` on a dedicated action) creating a `requested`
   subscription from a front-end form, mirroring `PaymentsController::actionRequest()`.
3. On creation, immediately generate the first linked `Payment` — extract a
   shared "create payment for this subscription" method out of
   `SubscriptionsService::advance()` so both the cron and this new flow use it.
4. Public subscription-status page analogous to `_pay.twig`/`actionPay()`,
   keyed by `Subscription::$uid` — needs `hasUris()` flipped to `true` (see
   open question 6) or a dedicated lookup-by-uid action instead.

**Phase 3 — Payment → subscription activation wiring**
1. Hook `Payment::afterSave()` (or a new event) so a `paid` payment whose
   `subscriptionId` resolves to a `requested` subscription calls
   `SubscriptionsService::activate()` and stamps `startedAt`/`nextDueDate`.
2. Add `getLatestPayment()`/`getNextPayment()` on `Subscription` plus a
   `nextPayment` table attribute on the element index (status pill + due date).

**Phase 4 — User cancel-at-period-end**
1. Resolve open questions 3 and 6.
2. Add the grace-period flag/status + a public controller action for it.
3. `SubscriptionsService::getDueSubscriptions()`/`advance()`: when a pending
   cancellation's cycle has arrived, finalize to `cancelled` instead of
   creating another payment.

**Phase 5 — Configurable renewal lead time**
1. Resolve open question 5.
2. Add a lead-time field to `SubscriptionType` (+ migration + edit-template
   field), mirroring `PaymentType::$defaultDueDateDays`'s shape.
3. Split "when to request payment" from "when the cycle starts" in
   `SubscriptionsService::advance()`/`getDueSubscriptions()`.
4. Resolve open question 2 and implement the late/unpaid-renewal behavior
   it settles on.

**Phase 6 — Subscription lifecycle emails**
1. Add template-override fields to `SubscriptionType`, mirroring
   `PaymentType`'s five.
2. Build the lifecycle emails listed in §3's conclusion as new
   `Send*EmailJob` subclasses on the existing `SendTemplatedEmailJob` base.
3. Make `_paymentReminder.twig`'s "7 days" copy data-driven once the lead
   time is configurable (Phase 5).

## 6. What's already solid — don't touch

- `Payment` status computation: expired-as-a-virtual-status, overpaid/partial
  math, and the fact that `PaymentQuery`'s SQL-level `effectiveStatusExpression()`
  stays exactly in sync with `Payment::getStatus()`'s in-PHP logic.
- The `statusHistory` JSON audit trail (payment/refund/cancellation/
  payer-reported entries) with `confirmedAt`/`confirmedByUserId` stamping.
- Admin pause/cancel for subscriptions.
- The bank-statement import/reconciliation flow (`ProcessController`/
  `TransactionsExportParser`) — untouched by anything above, out of scope
  for this review.
