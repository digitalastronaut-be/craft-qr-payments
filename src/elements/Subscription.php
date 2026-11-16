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

namespace digitalastronaut\craftqrpayments\elements;

use Craft;
use craft\base\Element;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\MoneyHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use digitalastronaut\craftqrpayments\controllers\SubscriptionsController;
use digitalastronaut\craftqrpayments\elements\conditions\SubscriptionCondition;
use digitalastronaut\craftqrpayments\elements\db\SubscriptionQuery;
use digitalastronaut\craftqrpayments\fieldlayoutelements\SubscriptionPaymentsField;
use digitalastronaut\craftqrpayments\fieldlayoutelements\SubscriptionStatusHistoryField;
use digitalastronaut\craftqrpayments\helpers\CpDateFormatter;
use digitalastronaut\craftqrpayments\helpers\HumanDuration;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\jobs\SendSubscriptionRequestedEmailJob;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

use DateTime;
use yii\db\Connection;
use yii\db\Expression;

/**
 * Class Subscription
 *
 * A recurrence schedule: `SubscriptionsService::advance()` stamps out the
 * next linked `Payment` from it, due on `getCoverageEnd()` — there's no
 * stored "next due date" to keep in sync; it's derived from the most
 * recent *paid* payment's own `dueDate` plus one effective interval, so
 * it can never drift out of sync with payment history (and self-heals if
 * a cron run gets missed, rather than silently going stale). No gateway
 * integration, same as a one-off payment — advancing just creates the
 * next payment for manual reconciliation.
 *
 * A real element (like `Payment`), not a plain model — a site can have a
 * lot of subscribers, and the native paginated, query-backed element
 * index scales where a hand-rolled `Craft.VueAdminTable` listing (loading
 * every row as one JSON blob) would not.
 *
 * Ownership mirrors how Craft Commerce ties an `Order` to a customer:
 * `userId` (nullable) is the authoritative owner wherever a site needs to
 * key access control off a stable identity, while `email` is a guest-contact
 * snapshot taken at signup — only required while no owner's linked, same as
 * `Payment`'s own email (see `defineRules()` and `getNotificationEmail()`).
 *
 * Price/cadence live entirely on `SubscriptionType` — every subscriber on
 * a plan is charged the same amount, on the same cadence; see
 * `getSubscriptionType()`.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.7.0-beta
 */
class Subscription extends Element {
    public const string STATUS_REQUESTED = 'requested';
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_EXPIRED = 'expired';
    public const string STATUS_CANCELLED = 'cancelled';

    private ?FieldLayout $fieldLayout = null;

    public ?int $userId = null;
    public string $email = '';
    public ?int $subscriptionTypeId = null;

    /**
     * Defaults to `requested` so a brand-new subscription never starts
     * `active` before its first payment is actually paid — including one
     * created via the CP's native "New subscription" draft flow, which
     * has no status field of its own and would otherwise just inherit
     * whatever this default is. `Payment::maybeActivateSubscription()`
     * flips it to `active` once that first payment clears.
     */
    public string $status = self::STATUS_REQUESTED;

    public ?DateTime $endedAt = null;

    /** @var array Backing store for `getStatusHistory()`/`setStatusHistory()`. */
    private array $_statusHistory = [];

    /** The `id` `beforeSave()`'s initial-status push returns, read back by `notifyRequested()` once the save succeeds. */
    private ?string $pendingRequestedHistoryEntryId = null;

    public static function displayName(): string { return Craft::t('qr-payments', 'Subscription'); }
    public static function lowerDisplayName(): string { return Craft::t('qr-payments', 'subscription'); }
    public static function pluralDisplayName(): string { return Craft::t('qr-payments', 'Subscriptions'); }
    public static function pluralLowerDisplayName(): string { return Craft::t('qr-payments', 'subscriptions'); }
    public static function refHandle(): ?string { return 'subscription'; }

    public static function trackChanges(): bool { return true; }
    public static function hasTitles(): bool { return true; }
    public static function hasUris(): bool { return false; }
    public static function hasDrafts(): bool { return true; }
    public static function isLocalized(): bool { return false; }
    public static function hasStatuses(): bool { return true; }

    public function canDuplicate(User $user): bool { return false; }

    /**
     * @param User $user
     * @return bool
     */
    public function canCreateDrafts(User $user): bool {
        if (parent::canCreateDrafts($user)) return true;
        return $user->can(SubscriptionsController::PERMISSION_MANAGE_SUBSCRIPTIONS);
    }

    /**
     * Title is fully derived — Craft's own `uid`, not the owner's name or
     * email — so it's computed here rather than left for a caller to set.
     * Unlike those, it's never personal data, never changes once assigned,
     * and (same reasoning as `Payment::$title` using `remittanceReference`)
     * is already guaranteed unique. `Elements::saveElement()` assigns
     * `uid` before `beforeValidate()` ever runs (even for a brand-new
     * element), so it's never blank here.
     *
     * @inheritdoc
     */
    public function beforeValidate(): bool {
        $this->title = $this->uid;

        return parent::beforeValidate();
    }

    /**
     * Normalizes the request-posted shape for `userId` (the element-select
     * field's "nothing selected" placeholder quirk) before Craft's generic
     * mass assignment (`setAttributes()`) handles the rest.
     *
     * @inheritdoc
     */
    public function setAttributesFromRequest(array $values): void {
        if (\array_key_exists('userId', $values)) {
            $ids = \is_array($values['userId']) ? $values['userId'] : ($values['userId'] === '' ? [] : (array) $values['userId']);
            $values['userId'] = $ids ? (int) $ids[0] : null;
        }

        parent::setAttributesFromRequest($values);
    }

    /**
     * @return User|null The owning Craft user, when one is set. Null for a
     * guest subscriber running on `email` alone.
     */
    public function getOwner(): ?User {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    /**
     * Prefers the owner's current account email over the `email` snapshot,
     * so reminders don't go stale if the account's address changes after
     * signup. Falls back to the snapshot for a guest subscriber.
     *
     * @return string
     */
    public function getNotificationEmail(): string {
        return $this->getOwner()?->email ?? $this->email;
    }

    /**
     * @return SubscriptionType|null
     */
    public function getSubscriptionType(): ?SubscriptionType {
        return $this->subscriptionTypeId !== null
            ? QrPayments::getInstance()->getSubscriptionTypes()->getSubscriptionTypeById($this->subscriptionTypeId)
            : null;
    }

    /**
     * Convenience pass-through — `SubscriptionsService::advance()` needs
     * the `PaymentType` behind this subscriber's plan to actually create
     * their next `Payment`.
     *
     * @return PaymentType|null
     */
    public function getPaymentType(): ?PaymentType {
        return $this->getSubscriptionType()?->getPaymentType();
    }

    /**
     * @return array<string, array{label: string, color: Color}> Status handle => definition
     */
    public static function statuses(): array {
        return [
            self::STATUS_REQUESTED => ['label' => Craft::t('qr-payments', 'Requested'), 'color' => Color::Blue],
            self::STATUS_ACTIVE => ['label' => Craft::t('qr-payments', 'Active'), 'color' => Color::Green],
            self::STATUS_EXPIRED => ['label' => Craft::t('qr-payments', 'Expired'), 'color' => Color::Red],
            self::STATUS_CANCELLED => ['label' => Craft::t('qr-payments', 'Cancelled'), 'color' => Color::Gray],
        ];
    }

    /**
     * Same reasoning as `Payment::getStatus()`: Craft's native enabled/
     * disabled toggle stays an independent axis from this plugin-owned
     * `$status` — and, same as `Payment`'s own `expired`, `STATUS_EXPIRED`
     * is computed here rather than ever written to `$status`: the moment
     * the subscription's latest payment is paid (this one or a later
     * one), `isPastDue()` stops being true on its own, no explicit
     * "un-expire" needed.
     *
     * Deliberately only applies to `active` — a `requested` subscription
     * whose first payment expired just stays `requested` (its "Last
     * payment" column already shows that payment as expired); this is
     * about a subscription that *was* active falling behind.
     *
     * @return string|null
     */
    public function getStatus(): ?string {
        if ($this->getIsDraft() || $this->archived) return parent::getStatus();

        if ($this->status === self::STATUS_ACTIVE && $this->isPastDue()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    /**
     * Hides Craft's native "Enabled" lightswitch from the sidebar — it's
     * an independent axis from this plugin-owned `$status` (see
     * `getStatus()`), and showing both next to each other reads as two
     * conflicting status controls rather than two different things.
     * `canonical` elements stay findable/queryable regardless either way,
     * since nothing here ever sets `enabled` to `false`.
     *
     * @return bool
     */
    protected function showStatusField(): bool {
        return false;
    }

    /**
     * Whether `getCoverageEnd()` has already passed — this subscriber's
     * paid-for period has run out and nothing newer has been paid yet.
     * Catches both ways that can happen: a renewal `Payment` was stamped
     * and is now sitting unpaid past its own `dueDate`, *and* the case
     * that check alone would miss — no renewal `Payment` ever got
     * stamped at all (e.g. a missed cron run) — since this doesn't care
     * whether a newer payment exists, only whether the last *paid* one's
     * coverage has elapsed.
     *
     * @return bool
     */
    private function isPastDue(): bool {
        $coverageEnd = $this->getCoverageEnd();

        return $coverageEnd !== null && $coverageEnd < DateTimeHelper::currentUTCDateTime();
    }

    /**
     * The date through which this subscriber is paid up: the most
     * recently *paid* (or overpaid) linked `Payment`'s own `dueDate`,
     * plus one effective interval. Null if none of this subscription's
     * payments have ever been paid.
     *
     * Deliberately not a stored column: deriving it from payment history
     * means it can never drift out of sync the way a separately-updated
     * "next due date" could (and did — see the plugin's own
     * `PAYMENTS_AND_SUBSCRIPTIONS_FLOW_PLAN.md` history), and it keeps
     * working even if a cron run that would have advanced a stored value
     * gets missed.
     *
     * @return DateTime|null
     */
    public function getCoverageEnd(): ?DateTime {
        if ($this->id === null) return null;

        $type = $this->getSubscriptionType();
        if ($type === null) return null;

        $latestPaidPayment = Payment::find()
            ->subscriptionId($this->id)
            ->status([Payment::STATUS_PAID, Payment::STATUS_OVERPAID])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if ($latestPaidPayment === null || $latestPaidPayment->dueDate === null) return null;

        return SubscriptionType::addInterval($latestPaidPayment->dueDate, $type->interval);
    }

    /**
     * @param User $user
     * @return bool
     */
    public function canView(User $user): bool {
        if (parent::canView($user)) return true;
        return $user->can(SubscriptionsController::PERMISSION_MANAGE_SUBSCRIPTIONS);
    }

    /**
     * @param User $user
     * @return bool
     */
    public function canSave(User $user): bool {
        if (parent::canSave($user)) return true;
        return $user->can(SubscriptionsController::PERMISSION_MANAGE_SUBSCRIPTIONS);
    }

    /**
     * Hard-deletable regardless of status — including `active` — same
     * authorization as `canView()`/`canSave()`. Cancelling
     * (`SubscriptionsService::cancel()`) is still the normal way to end a
     * subscription; this is for actually removing the record.
     *
     * @param User $user
     * @return bool
     */
    public function canDelete(User $user): bool {
        if (parent::canDelete($user)) return true;
        return $user->can(SubscriptionsController::PERMISSION_MANAGE_SUBSCRIPTIONS);
    }

    /**
     * @return SubscriptionQuery
     */
    public static function find(): ElementQueryInterface {
        return Craft::createObject(SubscriptionQuery::class, [static::class]);
    }

    /**
     * @return SubscriptionCondition
     */
    public static function createCondition(): ElementConditionInterface {
        return Craft::createObject(SubscriptionCondition::class, [static::class]);
    }

    /**
     * @return string[]
     */
    protected static function defineSearchableAttributes(): array {
        return ['email'];
    }

    /**
     * @param string $context
     * @return array[]
     */
    protected static function defineSources(string $context): array {
        $statusCounts = static::find()->statusCounts();

        $sources = [
            [
                'key' => 'all',
                'label' => Craft::t('qr-payments', 'All subscriptions'),
                'badgeCount' => array_sum($statusCounts),
            ],
        ];

        $sources[] = ['heading' => Craft::t('qr-payments', 'Statuses')];

        foreach (self::statuses() as $handle => $def) {
            $sources[] = [
                'key' => "status:{$handle}",
                'label' => $def['label'],
                'status' => $def['color']->value,
                'badgeCount' => $statusCounts[$handle] ?? 0,
                'criteria' => ['status' => $handle],
            ];
        }

        return $sources;
    }

    /**
     * @return array
     */
    protected static function defineSortOptions(): array {
        return [
            'title' => Craft::t('qr-payments', 'Owner'),
            [
                'label' => Craft::t('qr-payments', 'Next due date'),
                'orderBy' => function(int $dir, Connection $db): Expression {
                    $coverageEnd = SubscriptionQuery::coverageEndExpression();

                    return new Expression(
                        $coverageEnd->expression . ($dir === SORT_ASC ? ' ASC' : ' DESC'),
                        $coverageEnd->params,
                    );
                },
                'attribute' => 'nextDueDate',
            ],
            'dateCreated' => Craft::t('qr-payments', 'Date created'),
        ];
    }

    /**
     * @return array
     */
    protected static function defineTableAttributes(): array {
        return [
            'status' => ['label' => Craft::t('qr-payments', 'Status')],
            'lastPaymentStatus' => ['label' => Craft::t('qr-payments', 'Last payment')],
            'owner' => ['label' => Craft::t('qr-payments', 'Owner')],
            'subscriptionType' => ['label' => Craft::t('qr-payments', 'Type')],
            'amount' => ['label' => Craft::t('qr-payments', 'Amount')],
            'interval' => ['label' => Craft::t('qr-payments', 'Interval')],
            'validFor' => ['label' => Craft::t('qr-payments', 'Valid for')],
        ];
    }

    /**
     * @param string $source
     * @return string[]
     */
    protected static function defineDefaultTableAttributes(string $source): array {
        return ['status', 'lastPaymentStatus', 'owner', 'subscriptionType', 'amount', 'validFor'];
    }

    /**
     * @inheritdoc
     */
    protected function attributeHtml(string $attribute): string {
        return match ($attribute) {
            'owner' => ($owner = $this->getOwner()) ? Cp::elementChipHtml($owner) : Html::encode($this->email),
            'subscriptionType' => ($type = $this->getSubscriptionType()) ? Cp::chipHtml($type, ['hyperlink' => true]) : '',
            'amount' => Html::encode(MoneyHelper::toString($this->getSubscriptionType()?->getAmount()) ?: ''),
            'interval' => Html::encode(($type = $this->getSubscriptionType()) !== null ? (SubscriptionType::intervals()[$type->interval] ?? $type->interval) : ''),
            'validFor' => Html::encode($this->validForLabel()),
            'lastPaymentStatus' => ($latestPayment = $this->getLatestPayment()) ? (Cp::componentStatusLabelHtml($latestPayment) ?? '') : '',
            default => parent::attributeHtml($attribute),
        };
    }

    /**
     * `HumanDuration::until(getCoverageEnd())` — `"2 weeks"`, `"340 days"`,
     * etc.
     *
     * Blank for anything other than a raw `active` subscription (covers
     * both `requested`, which has no coverage yet, and `cancelled`,
     * where "valid for" no longer means anything regardless of what its
     * last paid payment covered) — intentionally checks `$this->status`
     * rather than `getStatus()`, since the virtual `expired` status is
     * exactly the "raw `active`, past due" case this should still cover.
     *
     * @return string
     */
    private function validForLabel(): string {
        if ($this->status !== self::STATUS_ACTIVE) return '';

        return HumanDuration::until($this->getCoverageEnd());
    }

    /**
     * The most recently created `Payment` linked to this subscription
     * (via `subscriptionId`), if any — a brand-new `requested` subscription
     * always has one by the time it exists (`SubscriptionsService::
     * requestSubscription()` creates them together), but a subscription
     * hand-created in the CP via the native draft flow doesn't yet.
     *
     * Orders by `id`, not `dateCreated`: that column only has
     * second-level precision, so a cron run or script that stamps out
     * more than one payment for the same subscription within the same
     * second would otherwise tie, leaving which one counts as "latest"
     * up to an unspecified DB tiebreak — `id` is always unambiguous.
     *
     * One query per call, so per-row in the element index this is one
     * query per subscription — fine for now, worth revisiting if the
     * index ever needs to stay fast with a lot of rows.
     *
     * @return Payment|null
     */
    public function getLatestPayment(): ?Payment {
        if ($this->id === null) return null;

        return Payment::find()
            ->subscriptionId($this->id)
            ->status(null)
            ->orderBy(['id' => SORT_DESC])
            ->one();
    }

    /**
     * @return array
     */
    public function getStatusHistory(): array {
        return $this->_statusHistory;
    }

    /**
     * @param array|string|null $value
     * @return void
     */
    public function setStatusHistory(array|string|null $value): void {
        if (\is_string($value)) $value = Json::decode($value) ?: [];

        $this->_statusHistory = $value ?? [];
    }

    /**
     * Appends one real, stored transition — `requested`/`active`/
     * `cancelled` — to `$statusHistory`. Unlike `Payment`'s richer set of
     * history "types" (payment/refund/payer-reported/...), a
     * subscription's status transitions already *are* the discrete
     * events, so the entry's `status` is just one of `self::statuses()`'s
     * own keys rather than a separate taxonomy.
     *
     * Deliberately never called for the virtual `expired` status —
     * `getStatusTimeline()` synthesizes those from payment history
     * instead of ever writing them here, same reasoning `getStatus()`
     * never writes `expired` back to `$status`.
     *
     * @param string $status
     * @param int|null $userId Who performed the transition, when it was a person (a CP admin). Null for a system-driven one (initial `requested` creation, `maybeActivateSubscription()`'s automatic reactivation).
     * @return string The new entry's `id` — see `Payment::pushStatusHistoryEntry()`'s return value for why.
     */
    public function pushStatusHistoryEntry(string $status, ?int $userId): string {
        $history = $this->getStatusHistory();

        $id = StringHelper::UUID();

        $history[] = [
            'id' => $id,
            'status' => $status,
            'date' => DateTimeHelper::currentUTCDateTime()->format(DateTime::ATOM),
            'userId' => $userId,
        ];

        $this->setStatusHistory($history);

        return $id;
    }

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool {
        // Same `$this->firstSave` trick `Payment::beforeSave()` uses for its
        // own `HISTORY_TYPE_CREATED` entry: this is the one moment that's
        // guaranteed to run exactly once, whichever of the two paths
        // created this subscription (`SubscriptionsService::
        // requestSubscription()`'s front-end flow, or the CP's native "New
        // subscription" draft-publish flow) — rather than duplicating this
        // in both.
        if ($this->firstSave) {
            $this->pendingRequestedHistoryEntryId = $this->pushStatusHistoryEntry($this->status, null);
        }

        return parent::beforeSave($isNew);
    }

    /**
     * The real transition log (`getStatusHistory()`) merged with
     * synthesized `expired` spans — periods between two paid payments (or
     * after the last one) where this subscriber's coverage had already
     * run out before they paid again, derived the same way `getStatus()`
     * derives a single virtual `expired` right now, just walked across
     * the whole payment history instead of only the present moment.
     *
     * Each entry is `['status' => ..., 'date' => ..., 'userId' => ...]`,
     * same shape as a real entry, plus `'until' => ...|null` and
     * `'synthesized' => true` on a derived `expired` span (`'until'` null
     * means it's still ongoing). Sorted oldest first.
     *
     * @return array
     */
    public function getStatusTimeline(): array {
        // Snapshotted separately from `$timeline` below: `wasActiveAt()`
        // must only ever consult real entries, never a synthesized
        // `expired` span appended earlier in this same loop — otherwise a
        // later gap's check could read that earlier span's status back
        // instead of the real one in effect at the time.
        $realEntries = array_map(
            static fn(array $entry) => $entry + ['synthesized' => false],
            $this->getStatusHistory(),
        );

        $timeline = $realEntries;

        foreach ($this->getExpiredGaps() as $gap) {
            if (!$this->wasActiveAt($gap['from'], $realEntries)) continue;

            $timeline[] = [
                'status' => self::STATUS_EXPIRED,
                'date' => $gap['from']->format(DateTime::ATOM),
                'until' => $gap['to']?->format(DateTime::ATOM),
                'userId' => null,
                'synthesized' => true,
            ];
        }

        usort($timeline, static fn(array $a, array $b) => $a['date'] <=> $b['date']);

        return $timeline;
    }

    /**
     * Every window this subscriber went unpaid past their paid-up
     * coverage: for each paid payment, compares the period it covered
     * (its own `dueDate` plus one interval — same formula
     * `getCoverageEnd()` uses for the latest one) against when the *next*
     * paid payment actually cleared (`Payment::$confirmedAt`). A gap
     * after the most recent paid payment (nothing's cleared since) stays
     * open-ended (`'to' => null`) rather than being dropped, so a
     * subscriber currently sitting expired still shows it.
     *
     * @return array<array{from: DateTime, to: DateTime|null}>
     */
    private function getExpiredGaps(): array {
        if ($this->id === null) return [];

        $type = $this->getSubscriptionType();
        if ($type === null) return [];

        $paidPayments = array_values(array_filter(
            Payment::find()->subscriptionId($this->id)->status(null)->orderBy(['dueDate' => SORT_ASC])->all(),
            static fn(Payment $payment) => $payment->dueDate !== null
                && \in_array($payment->status, [Payment::STATUS_PAID, Payment::STATUS_OVERPAID], true),
        ));

        $gaps = [];

        foreach ($paidPayments as $i => $payment) {
            $coverageEnd = SubscriptionType::addInterval($payment->dueDate, $type->interval);
            $nextPaid = $paidPayments[$i + 1] ?? null;

            if ($nextPaid !== null) {
                if ($nextPaid->confirmedAt !== null && $nextPaid->confirmedAt > $coverageEnd) {
                    $gaps[] = ['from' => $coverageEnd, 'to' => $nextPaid->confirmedAt];
                }
            } elseif ($coverageEnd < DateTimeHelper::currentUTCDateTime()) {
                $gaps[] = ['from' => $coverageEnd, 'to' => null];
            }
        }

        return $gaps;
    }

    /**
     * Whether the real (non-synthesized) status as of `$at` was `active` —
     * `getStatusTimeline()` only turns a payment gap into a shown
     * `expired` span when this is true, so a window where the subscriber
     * was actually `cancelled` (and the gap is just an artifact of no one
     * ever paying again) doesn't get mislabeled as a lapse.
     *
     * @param DateTime $at
     * @param array $realEntries Unsynthesized entries only (`getStatusHistory()`'s own shape).
     * @return bool
     */
    private function wasActiveAt(DateTime $at, array $realEntries): bool {
        $status = null;

        foreach ($realEntries as $entry) {
            $entryDate = DateTimeHelper::toDateTime($entry['date'] ?? null);

            if ($entryDate !== null && $entryDate <= $at) {
                $status = $entry['status'];
            }
        }

        return $status === self::STATUS_ACTIVE;
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        return array_merge(parent::defineRules(), [
            // Excludes SCENARIO_ESSENTIALS (Craft's own convention for
            // native-attribute requiredness — see Element::defineRules()'s
            // own title/slug rules) so the very first save of a brand-new
            // unpublished draft succeeds with these still blank; they're
            // enforced on every save after that.
            [['subscriptionTypeId'], 'required', 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]],

            // Same `Payment`-style exception: not required once an owner's
            // linked, since `getNotificationEmail()` prefers that account's
            // own address over this snapshot at that point anyway.
            [['email'], 'required', 'when' => fn(self $model) => $model->userId === null, 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]],

            [['userId'], 'integer'],
            [['userId'], 'validateOwnerExists'],

            [['email'], 'email'],
            [['email'], 'string', 'max' => 255],

            [['subscriptionTypeId'], 'integer'],
            [['subscriptionTypeId'], 'validateSubscriptionTypeExists'],

            [['status'], 'in', 'range' => array_keys(self::statuses())],

            [['endedAt'], 'safe'],
            [['statusHistory'], 'safe'],
        ]);
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validateOwnerExists(string $attribute): void {
        $id = $this->$attribute;

        if ($id === null) return;

        if (!User::find()->id($id)->status(null)->exists()) {
            $this->addError($attribute, Craft::t('qr-payments', 'Could not find the selected user.'));
        }
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validateSubscriptionTypeExists(string $attribute): void {
        $id = $this->$attribute;

        if ($id === null) return;

        if (QrPayments::getInstance()->getSubscriptionTypes()->getSubscriptionTypeById($id) === null) {
            $this->addError($attribute, Craft::t('qr-payments', 'Could not find the selected subscription type.'));
        }
    }

    /**
     * @return string
     */
    public function cpEditUrl(): string {
        return UrlHelper::cpUrl("qr-payments/subscriptions/{$this->id}");
    }

    /**
     * Without this, `ElementHelper::postEditUrl()` falls back to the CP's
     * `postCpLoginRedirect` setting (`entries` by default) once a save
     * posts no `redirect` param of its own — exactly the native "New
     * subscription" draft-publish flow, which never sets one.
     *
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string {
        return $this->cpEditUrl();
    }

    /**
     * @return array|string|null
     */
    protected function route(): array|string|null {
        return null;
    }

    /**
     * Code-only field layout (no designer route) — this plugin owns the
     * shape of a subscription's editable native attributes entirely.
     * Every one of them lives in the sidebar instead (see
     * `getSidebarHtml()`), so the main tab is just `SubscriptionPaymentsField`'s
     * read-only payment history plus `SubscriptionStatusHistoryField`'s
     * read-only status timeline.
     *
     * @param string|null $source
     * @return FieldLayout[]
     */
    protected static function defineFieldLayouts(?string $source): array {
        return [self::buildFieldLayout()];
    }

    /**
     * @return FieldLayout
     */
    private static function buildFieldLayout(): FieldLayout {
        $layout = new FieldLayout(['type' => static::class]);
        $tab = new FieldLayoutTab(['name' => Craft::t('qr-payments', 'Subscription'), 'layout' => $layout]);

        $tab->setElements([new SubscriptionPaymentsField(), new SubscriptionStatusHistoryField()]);
        $layout->setTabs([$tab]);

        return $layout;
    }

    /**
     * `defineFieldLayouts()` alone doesn't wire anything up for a specific
     * instance — the base `Element::getFieldLayout()` only ever resolves
     * `$this->fieldLayoutId` (a real `fieldlayouts` row id), which nothing
     * here ever sets. Same gotcha `Payment::getFieldLayout()` already
     * works around for its own (per-`PaymentType`) layout — overridden
     * here too, just with one static layout instead of one per type.
     *
     * @return FieldLayout|null
     */
    public function getFieldLayout(): ?FieldLayout {
        return $this->fieldLayout ??= self::buildFieldLayout();
    }

    /**
     * @param bool $static
     * @return string
     */
    public function getSidebarHtml(bool $static): string {
        // One `.meta` box with the subscription type/owner/contact fields
        // stacked as rows inside it — same grouping `Payment::getSidebarHtml()`
        // uses.
        $ownerGroup = Html::tag('div',
            $this->subscriptionTypeFieldHtml($static) . $this->ownerFieldHtml($static) . $this->emailFieldHtml($static),
            ['class' => 'meta'],
        );

        return parent::getSidebarHtml($static) . $ownerGroup;
    }

    /**
     * Which plan this subscriber is enrolled in — its price, interval, and
     * payment type all come from here (see `getSubscriptionType()`).
     * Lived in the main tab as its own field-layout element
     * (`SubscriptionFieldsField`) until the sidebar took over every other
     * editable native attribute; moved here to match, rather than being
     * the one editable field left behind in an otherwise read-only main
     * tab.
     *
     * @param bool $static
     * @return string
     */
    protected function subscriptionTypeFieldHtml(bool $static): string {
        $options = array_map(
            static fn(SubscriptionType $type) => ['label' => $type->name, 'value' => $type->id],
            QrPayments::getInstance()->getSubscriptionTypes()->getAllSubscriptionTypes(),
        );

        return Cp::selectizeFieldHtml([
            'label' => Craft::t('qr-payments', 'Type'),
            'id' => 'subscriptionTypeId',
            'name' => 'subscriptionTypeId',
            'options' => $options,
            'value' => $this->subscriptionTypeId,
            'required' => true,
            'disabled' => $static,
            'errors' => $this->getErrors('subscriptionTypeId'),
        ]);
    }

    /**
     * The linked-account half of the owner/contact pair — stacked above
     * `emailFieldHtml()` in the sidebar. Editable for the life of the
     * subscription: a guest subscriber can be linked to an account later
     * (e.g. once they register), same reasoning `Payment`'s own owner
     * field has.
     *
     * @param bool $static
     * @return string
     */
    protected function ownerFieldHtml(bool $static): string {
        $owner = $this->getOwner();

        return Cp::elementSelectFieldHtml([
            'label' => Craft::t('qr-payments', 'Owner'),
            'id' => 'userId',
            'name' => 'userId',
            'elementType' => User::class,
            'elements' => $owner ? [$owner] : [],
            'sources' => '*',
            'limit' => 1,
            'disabled' => $static,
            'errors' => $this->getErrors('userId'),
        ]);
    }

    /**
     * The contact half of the owner/contact pair. Only actually required
     * while there's no linked account (see `defineRules()`) — same as
     * `Payment`'s own email: once an owner's linked, that account's
     * address is what notifications go to (`getNotificationEmail()`), so
     * this just stays around as a fallback snapshot, editable here for
     * corrections.
     *
     * Deliberately never passes `required` here (unlike `subscriptionTypeFieldHtml()`'s
     * always-required `selectizeFieldHtml()` call): the owner field above
     * is a sidebar `elementSelect` whose selection changes without a page
     * reload, so a `required` HTML attribute derived from `$this->userId`
     * at render time would go stale the moment someone picks (or clears)
     * an owner, blocking — or failing to block — submission based on
     * who *was* linked rather than who's linked right now. The `when`
     * condition on `defineRules()`'s rule re-checks the real, just-posted
     * `userId` on every save instead, so it's never stale.
     *
     * @param bool $static
     * @return string
     */
    protected function emailFieldHtml(bool $static): string {
        return Cp::textFieldHtml([
            'label' => Craft::t('qr-payments', 'Email'),
            'id' => 'email',
            'name' => 'email',
            'type' => 'email',
            'value' => $this->email,
            'disabled' => $static,
            'errors' => $this->getErrors('email'),
        ]);
    }

    /**
     * @return array
     */
    protected function metadata(): array {
        return array_merge(parent::metadata(), [
            Craft::t('app', 'Status') => fn() => Cp::componentStatusLabelHtml($this),
            Craft::t('qr-payments', 'Amount') => fn() => MoneyHelper::toString($this->getSubscriptionType()?->getAmount()) ?: false,
            Craft::t('qr-payments', 'Interval') => fn() => ($type = $this->getSubscriptionType()) !== null ? (SubscriptionType::intervals()[$type->interval] ?? $type->interval) : false,
            Craft::t('qr-payments', 'Next due date') => fn() => ($coverageEnd = $this->getCoverageEnd())
                ? CpDateFormatter::format($coverageEnd)
                : false,
            Craft::t('qr-payments', 'Valid for') => fn() => ($label = $this->validForLabel()) !== '' ? $label : false,
            Craft::t('qr-payments', 'Ended at') => fn() => $this->endedAt
                ? CpDateFormatter::format($this->endedAt)
                : false,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getAdditionalButtons(): string {
        return $this->statusActionButtonsHtml() . parent::getAdditionalButtons();
    }

    /**
     * @return string
     */
    private function statusActionButtonsHtml(): string {
        if (!$this->id || $this->getIsUnpublishedDraft()) return '';

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$this->canSave($user)) return '';

        $buttons = [];

        if ($this->status !== self::STATUS_CANCELLED) {
            $buttons[] = $this->statusActionButtonHtml('cancel', Craft::t('qr-payments', 'Cancel'), Craft::t('qr-payments', 'Cancel this subscription?'));
        }

        if (empty($buttons)) return '';

        return Html::tag('div', implode('', $buttons), ['class' => 'btngroup']);
    }

    /**
     * Relies on `formsubmit` submitting the enclosing `elements/edit`
     * form, which already posts `elementId` for free.
     *
     * @param string $action
     * @param string $label
     * @param string|null $confirm
     * @return string
     */
    private function statusActionButtonHtml(string $action, string $label, ?string $confirm = null): string {
        return Html::tag('button', $label, [
            'type' => 'button',
            'class' => ['btn', 'formsubmit'],
            'data' => array_filter([
                'action' => "qr-payments/subscriptions/{$action}",
                'confirm' => $confirm,
            ]),
        ]);
    }

    /**
     * @param bool $isNew
     * @return void
     */
    public function afterSave(bool $isNew): void {
        if (!$this->propagating) {
            Db::upsert(Table::QR_PAYMENTS_SUBSCRIPTIONS, [
                'id' => $this->id,
                'userId' => $this->userId,
                'email' => $this->email,
                'subscriptionTypeId' => $this->subscriptionTypeId,
                'status' => $this->status,
                'statusHistory' => $this->getStatusHistory(),
                'endedAt' => Db::prepareDateForDb($this->endedAt),
            ], [
                'userId' => $this->userId,
                'email' => $this->email,
                'subscriptionTypeId' => $this->subscriptionTypeId,
                'status' => $this->status,
                'statusHistory' => $this->getStatusHistory(),
                'endedAt' => Db::prepareDateForDb($this->endedAt),
            ]);

            // Must run after the upsert above: `SendSubscriptionRequestedEmailJob`
            // re-fetches the subscription from the DB rather than using
            // this in-memory instance, same reason `Payment::afterSave()`
            // notifies after its own upsert.
            if ($this->firstSave) $this->notifyRequested();
        }

        parent::afterSave($isNew);

        // Only once this is a real (non-draft) save: an unpublished draft
        // is still missing required data (no `subscriptionTypeId` yet,
        // typically) while it's being filled in, and every draft autosave
        // would otherwise call this again for nothing.
        // `SubscriptionsService::ensureFirstPayment()` itself is a no-op
        // once a payment exists, so this is safe to run on every
        // subsequent real save too (an admin editing an existing
        // subscription, `activate()`/`pause()`/`cancel()`, etc.) — it's
        // what actually makes a subscription created via the CP's native
        // "New subscription" draft flow get a first payment at all, the
        // same way `SubscriptionsService::requestSubscription()`'s
        // front-end flow already did.
        if (!$this->propagating && !$this->getIsDraft()) {
            QrPayments::getInstance()->getSubscriptions()->ensureFirstPayment($this);
        }
    }

    /**
     * @return void
     */
    private function notifyRequested(): void {
        Craft::$app->getQueue()->push(new SendSubscriptionRequestedEmailJob([
            'subscriptionId' => $this->id,
            'historyEntryId' => $this->pendingRequestedHistoryEntryId,
        ]));

        App::maxPowerCaptain();
        Craft::$app->queue->run();
    }
}
