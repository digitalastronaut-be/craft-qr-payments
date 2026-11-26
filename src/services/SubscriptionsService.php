<?php
/**
 * QR payments plugin for Craft CMS
 *
 * Generates EPC QR code SEPA payment requests and lets the site owner
 * manually track and reconcile payment status from the control panel.
 *
 * @link      https://digitalastronaut.be
 * @copyright Copyright (c) 2026 Digitalastronaut
 */

namespace digitalastronaut\craftqrpayments\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\MoneyHelper;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\events\SubscriptionEvent;
use digitalastronaut\craftqrpayments\exceptions\DuplicateSubscriptionException;
use digitalastronaut\craftqrpayments\jobs\SendSubscriptionActivatedEmailJob;
use digitalastronaut\craftqrpayments\jobs\SendSubscriptionCancelledEmailJob;
use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

use DateTime;
use InvalidArgumentException;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Class SubscriptionsService
 *
 * CRUD and lookups now go through `Subscription::find()` / `Craft::$app
 * ->getElements()->saveElement()` directly — `Subscription` is a real
 * element, not a plain model, so this service only holds the domain
 * operations that are more than plain persistence: status transitions
 * (firing events) and `advance()`.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SubscriptionsService extends Component {
    public const string EVENT_AFTER_ACTIVATE_SUBSCRIPTION = 'afterActivateSubscription';
    public const string EVENT_AFTER_CANCEL_SUBSCRIPTION = 'afterCancelSubscription';

    /**
     * Every `active` subscription whose coverage has run out (see
     * `Subscription::getCoverageEnd()`) and whose latest payment is
     * already settled — what `SubscriptionController::actionAdvance()`
     * runs to decide what to advance. Excludes a subscription whose
     * latest payment is still outstanding (whether or not it's gone
     * `expired`): one unpaid invoice at a time, never stamp a second one
     * on top of it.
     *
     * Filtered in PHP against every `active` subscription, rather than
     * in SQL: this only ever runs from the daily cron, where that's
     * simpler — and, at this project's scale, no slower in practice —
     * than replicating `SubscriptionQuery::coverageEndExpression()`'s
     * correlated-subquery logic a second time. Queries the raw `status`
     * column (`.status(null)`, filtered here) rather than `.status(
     * STATUS_ACTIVE)`, which would also (correctly, for the CP) exclude
     * a subscription whose coverage just ran out — exactly the ones
     * this is looking for.
     *
     * @return Subscription[]
     */
    public function getDueSubscriptions(): array {
        $now = DateTimeHelper::currentUTCDateTime();

        return array_values(array_filter(
            Subscription::find()->status(null)->all(),
            function (Subscription $subscription) use ($now) {
                if ($subscription->status !== Subscription::STATUS_ACTIVE) return false;

                $latestPayment = $subscription->getLatestPayment();
                if ($latestPayment === null) return false;
                if (!\in_array($latestPayment->getStatus(), [Payment::STATUS_PAID, Payment::STATUS_OVERPAID], true)) return false;

                $coverageEnd = $subscription->getCoverageEnd();

                return $coverageEnd !== null && $coverageEnd <= $now;
            },
        ));
    }

    /**
     * Pushes a `Subscription::STATUS_ACTIVE` history entry only when this
     * actually changes the raw status — called on every save of a
     * subscription-linked payment (`Payment::maybeActivateSubscription()`),
     * not just on a real transition, so re-activating an already-`active`
     * subscription (the ordinary renewal-paid case) mustn't add a
     * duplicate entry each time.
     *
     * @param Subscription $subscription
     * @param int|null $userId Who activated it, when it was a person (a CP admin). Null for the automatic reactivation `maybeActivateSubscription()` triggers.
     * @return bool False if the subscription failed to save.
     */
    public function activate(Subscription $subscription, ?int $userId = null): bool {
        $wasActive = $subscription->status === Subscription::STATUS_ACTIVE;
        $historyEntryId = null;

        $subscription->status = Subscription::STATUS_ACTIVE;

        if (!$wasActive) {
            $historyEntryId = $subscription->pushStatusHistoryEntry(Subscription::STATUS_ACTIVE, $userId);
        }

        if (!Craft::$app->getElements()->saveElement($subscription)) return false;

        if (!$wasActive) $this->notify(SendSubscriptionActivatedEmailJob::class, $subscription->id, $historyEntryId);

        if ($this->hasEventHandlers(self::EVENT_AFTER_ACTIVATE_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_AFTER_ACTIVATE_SUBSCRIPTION, new SubscriptionEvent(['subscription' => $subscription]));
        }

        return true;
    }

    /**
     * @param Subscription $subscription
     * @param int|null $userId Who cancelled it, when it was a person (a CP admin). Null for a system-driven cancellation.
     * @return bool False if the subscription failed to save.
     */
    public function cancel(Subscription $subscription, ?int $userId = null): bool {
        $wasCancelled = $subscription->status === Subscription::STATUS_CANCELLED;
        $historyEntryId = null;

        if (!$wasCancelled) {
            $historyEntryId = $subscription->pushStatusHistoryEntry(Subscription::STATUS_CANCELLED, $userId);
        }

        $subscription->status = Subscription::STATUS_CANCELLED;
        $subscription->endedAt = DateTimeHelper::currentUTCDateTime();

        if (!Craft::$app->getElements()->saveElement($subscription)) return false;

        if (!$wasCancelled) $this->notify(SendSubscriptionCancelledEmailJob::class, $subscription->id, $historyEntryId);

        if ($this->hasEventHandlers(self::EVENT_AFTER_CANCEL_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_AFTER_CANCEL_SUBSCRIPTION, new SubscriptionEvent(['subscription' => $subscription]));
        }

        return true;
    }

    /**
     * Shared by `activate()`/`cancel()` — both queue the same shape of job
     * (`subscriptionId` + `historyEntryId`) right after a successful save,
     * same eager-run pattern as every other notify call in this plugin.
     *
     * @param class-string<\digitalastronaut\craftqrpayments\jobs\SendTemplatedEmailJob> $jobClass
     * @param int $subscriptionId
     * @param string|null $historyEntryId
     * @return void
     */
    private function notify(string $jobClass, int $subscriptionId, ?string $historyEntryId): void {
        Craft::$app->getQueue()->push(new $jobClass([
            'subscriptionId' => $subscriptionId,
            'historyEntryId' => $historyEntryId,
        ]));

        App::maxPowerCaptain();
        Craft::$app->queue->run();
    }

    /**
     * Stamps out this subscription's next `Payment`, due on
     * `getCoverageEnd()` (or today, if nothing's ever been paid yet —
     * shouldn't happen in practice, since `getDueSubscriptions()` never
     * returns a subscription without a paid payment, but a sane
     * fallback regardless). No gateway integration, same as a one-off
     * payment: this just creates the next payment for manual
     * reconciliation. Doesn't touch the subscription itself — there's
     * nothing left to update; `getCoverageEnd()` moves forward on its
     * own once this new payment is paid.
     *
     * @param Subscription $subscription
     * @return Payment
     * @throws InvalidConfigException if `subscriptionTypeId` doesn't resolve to a real `SubscriptionType`, or its `paymentTypeId` doesn't resolve to a real `PaymentType`
     * @throws InvalidArgumentException if the beneficiary/IBAN/BIC settings don't produce a valid EPC payload
     * @throws Exception if the generated payment fails validation on save
     */
    public function advance(Subscription $subscription): Payment {
        $dueDate = $subscription->getCoverageEnd() ?? DateTimeHelper::currentUTCDateTime();

        return $this->createPaymentFor($subscription, $dueDate);
    }

    /**
     * Creates a new `requested` subscription for `$type` and immediately
     * generates its first linked `Payment`, due today — the subscription
     * stays `requested` until that payment is paid (activating it is a
     * separate, not-yet-built step; see `activate()`).
     *
     * @param SubscriptionType $type
     * @param string $email
     * @param int|null $userId The Craft user this subscription belongs to, when the subscriber has an account. Null for a guest subscriber.
     * @return Payment The subscription's first payment — `$payment->getSubscription()` gets back to the subscription itself.
     * @throws DuplicateSubscriptionException if the identified subscriber already has a `requested`/`active` subscription of this type — see `hasOutstandingSubscription()`
     * @throws Exception if the subscription or the generated payment fails validation on save
     * @throws InvalidConfigException if `$type`'s `paymentTypeId` doesn't resolve to a real `PaymentType`
     * @throws InvalidArgumentException if the beneficiary/IBAN/BIC settings don't produce a valid EPC payload
     */
    public function requestSubscription(SubscriptionType $type, string $email, ?int $userId = null): Payment {
        if ($this->hasOutstandingSubscription($type, $userId, $email)) {
            throw new DuplicateSubscriptionException(Craft::t('qr-payments', 'Already has a requested or active {name} subscription.', [
                'name' => $type->name,
            ]));
        }

        $subscription = new Subscription();
        $subscription->subscriptionTypeId = $type->id;
        $subscription->email = $email;
        $subscription->userId = $userId;
        $subscription->status = Subscription::STATUS_REQUESTED;

        if (!Craft::$app->getElements()->saveElement($subscription)) {
            throw new Exception(sprintf(
                'Failed to create subscription: %s',
                json_encode($subscription->getErrors())
            ));
        }

        // `Subscription::afterSave()` already generated the first payment
        // as part of the save above (see `ensureFirstPayment()`) — this
        // just reads it back.
        $payment = $subscription->getLatestPayment();

        if ($payment === null) {
            throw new Exception("Subscription #{$subscription->id} was saved but has no first payment.");
        }

        return $payment;
    }

    /**
     * Whether the identified subscriber already has a `requested`/
     * `active` `Subscription` of `$type` — the one-per-type-per-subscriber
     * rule `requestSubscription()` enforces ahead of its own save, and
     * `Subscription::validateNotDuplicate()` enforces again (as a
     * backstop for every other save path) at the element-validation
     * level, excluding the subscription being saved itself via
     * `$excludeId`.
     *
     * `$userId` is authoritative when set — never falls back to `$email`
     * in that case, since a logged-in requester can submit any `email`
     * in the form independent of their account's real address (see
     * `Subscription`'s own "email is a guest-contact snapshot, not an
     * identity" distinction) — so keying off it instead would let the
     * same person bypass this just by varying what they type in.
     * `$email` is compared case-insensitively (`SubscriptionQuery`'s own
     * `email()` criteria does this), same as Craft's own `User` element
     * compares email for uniqueness.
     *
     * @param SubscriptionType $type
     * @param int|null $userId
     * @param string $email
     * @param int|null $excludeId A subscription id to exclude from the check — the one currently being saved, when called from `Subscription::validateNotDuplicate()`.
     * @return bool
     */
    public function hasOutstandingSubscription(SubscriptionType $type, ?int $userId, string $email, ?int $excludeId = null): bool {
        $query = Subscription::find()
            ->subscriptionTypeId($type->id)
            ->status([Subscription::STATUS_REQUESTED, Subscription::STATUS_ACTIVE]);

        if ($excludeId !== null) $query->id(['not', $excludeId]);

        if ($userId !== null) {
            $query->userId($userId);
        } elseif ($email !== '') {
            $query->email($email);
        } else {
            return false;
        }

        return $query->exists();
    }

    /**
     * Creates `$subscription`'s first linked `Payment`, unless it already
     * has one. Called from `Subscription::afterSave()` on every real
     * (non-draft) save, so a subscription created via the CP's native
     * draft flow gets its first payment exactly the same way one created
     * through `requestSubscription()`'s front-end flow does — neither
     * caller has to remember to do this itself.
     *
     * @param Subscription $subscription
     * @return Payment|null The newly created payment, or null if one already existed.
     * @throws InvalidConfigException if `subscriptionTypeId` doesn't resolve to a real `SubscriptionType`, or its `paymentTypeId` doesn't resolve to a real `PaymentType`
     * @throws InvalidArgumentException if the beneficiary/IBAN/BIC settings don't produce a valid EPC payload
     * @throws Exception if the generated payment fails validation on save
     */
    public function ensureFirstPayment(Subscription $subscription): ?Payment {
        if ($subscription->getLatestPayment() !== null) return null;

        return $this->createPaymentFor($subscription);
    }

    /**
     * The shared half of `advance()`/`ensureFirstPayment()`: creates one
     * `Payment` for `$subscription` at its subscription type's amount,
     * linked back via `subscriptionId`.
     *
     * Leaves `$dueDate` as `null` through to `createPayment()` rather than
     * defaulting it here: `Payment::beforeValidate()` then fills it in
     * from the `PaymentType`'s own `defaultDueDateDays`, same grace period
     * a one-off payment gets. `advance()` passes an explicit due date of
     * its own (the cycle date that's actually due) — only
     * `ensureFirstPayment()`'s brand-new first payment relies on this
     * fallback, and that one should get the normal grace period, not be
     * due (and therefore expired) the instant it's created.
     *
     * @param Subscription $subscription
     * @param DateTime|null $dueDate Explicit due date, when the caller has one (`advance()` does); left `null` to fall back to the `PaymentType`'s `defaultDueDateDays`.
     * @return Payment
     * @throws InvalidConfigException if `subscriptionTypeId` doesn't resolve to a real `SubscriptionType`, or its `paymentTypeId` doesn't resolve to a real `PaymentType`
     * @throws InvalidArgumentException if the beneficiary/IBAN/BIC settings don't produce a valid EPC payload
     * @throws Exception if the generated payment fails validation on save
     */
    private function createPaymentFor(Subscription $subscription, ?DateTime $dueDate = null): Payment {
        $subscriptionType = $subscription->getSubscriptionType();
        $type = $subscriptionType?->getPaymentType();

        if ($subscriptionType === null || $type === null) {
            throw new InvalidConfigException("Subscription #{$subscription->id} (subscription type #{$subscription->subscriptionTypeId}) has no resolvable payment type.");
        }

        return QrPayments::getInstance()->getPayments()->createPayment(
            type: $type,
            amount: (float) MoneyHelper::toDecimal($subscriptionType->getAmount()),
            email: $subscription->getNotificationEmail(),
            dueDate: $dueDate,
            subscriptionId: $subscription->id,
            userId: $subscription->userId,
        );
    }
}
