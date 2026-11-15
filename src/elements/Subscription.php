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
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\MoneyHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use digitalastronaut\craftqrpayments\controllers\SubscriptionsController;
use digitalastronaut\craftqrpayments\elements\conditions\SubscriptionCondition;
use digitalastronaut\craftqrpayments\elements\db\SubscriptionQuery;
use digitalastronaut\craftqrpayments\fieldlayoutelements\SubscriptionFieldsField;
use digitalastronaut\craftqrpayments\fieldlayoutelements\SubscriptionPaymentsField;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

use Money\Money;
use DateTime;
use yii\base\InvalidConfigException;

/**
 * Class Subscription
 *
 * A recurrence schedule: `SubscriptionsService::advance()` stamps out the
 * next linked `Payment` from it and pushes `nextDueDate` forward. No
 * gateway integration, same as a one-off payment — advancing just creates
 * the next payment for manual reconciliation.
 *
 * A real element (like `Payment`), not a plain model — a site can have a
 * lot of subscribers, and the native paginated, query-backed element
 * index scales where a hand-rolled `Craft.VueAdminTable` listing (loading
 * every row as one JSON blob) would not.
 *
 * Ownership mirrors how Craft Commerce ties an `Order` to a customer:
 * `userId` (nullable) is the authoritative owner wherever a site needs to
 * key access control off a stable identity, while `email` is always
 * present as a snapshot taken at signup.
 *
 * Price/cadence live on `SubscriptionType`, not here — `$amount`/`$interval`
 * are an optional per-subscriber *override*: `getEffectiveAmount()`/
 * `getEffectiveInterval()` fall back to the live `SubscriptionType` values
 * when blank.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.7.0-beta
 */
class Subscription extends Element {
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_PAUSED = 'paused';
    public const string STATUS_CANCELLED = 'cancelled';

    private ?FieldLayout $fieldLayout = null;

    public ?int $userId = null;
    public string $email = '';
    public ?int $subscriptionTypeId = null;

    private ?Money $_amount = null;

    public ?string $interval = null;
    public string $status = self::STATUS_ACTIVE;

    public ?DateTime $nextDueDate = null;
    public ?DateTime $startedAt = null;
    public ?DateTime $endedAt = null;

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
     * Title is fully derived — always the owner's name, or the email
     * snapshot for a guest subscriber — so it's computed here rather than
     * left for a caller to set, the way `Payment::$title` is (that one
     * needs a generated reference first).
     *
     * @inheritdoc
     */
    public function beforeValidate(): bool {
        $this->title = $this->getOwner()?->getFriendlyName() ?: ($this->email ?: Craft::t('qr-payments', 'Subscription'));

        return parent::beforeValidate();
    }

    /**
     * Normalizes the request-posted shapes for `userId` (the element-select
     * field's "nothing selected" placeholder quirk), `amount` (money-field
     * array), and the date fields (date/time array) before Craft's generic
     * mass assignment (`setAttributes()`) handles the rest.
     *
     * @inheritdoc
     */
    public function setAttributesFromRequest(array $values): void {
        if (\array_key_exists('userId', $values)) {
            $ids = \is_array($values['userId']) ? $values['userId'] : ($values['userId'] === '' ? [] : (array) $values['userId']);
            $values['userId'] = $ids ? (int) $ids[0] : null;
        }

        if (\array_key_exists('amount', $values) && (\is_array($values['amount']) ? ($values['amount']['value'] ?? '') === '' : $values['amount'] === '')) {
            $values['amount'] = null;
        }

        if (\array_key_exists('interval', $values) && $values['interval'] === '') {
            $values['interval'] = null;
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
     * @return Money|null The per-subscriber override, or null if this
     * subscriber just follows the `SubscriptionType`'s current amount —
     * see `getEffectiveAmount()` for the value actually charged.
     */
    public function getAmount(): ?Money {
        return $this->_amount;
    }

    /**
     * @param Money|array|string|int|float|null $value
     * @return void
     */
    public function setAmount(Money|array|string|int|float|null $value): void {
        $this->_amount = Payment::parseMoney($value);
    }

    /**
     * The amount this subscriber is actually charged: their own override
     * when set, else the `SubscriptionType`'s current amount.
     *
     * @return Money
     * @throws InvalidConfigException if there's no override and `subscriptionTypeId` doesn't resolve to a real `SubscriptionType`
     */
    public function getEffectiveAmount(): Money {
        if ($this->_amount !== null) return $this->_amount;

        $amount = $this->getSubscriptionType()?->getAmount();

        if ($amount === null) {
            throw new InvalidConfigException("Subscription #{$this->id} has no amount override and its subscription type has none either.");
        }

        return $amount;
    }

    /**
     * Same fallback as `getEffectiveAmount()`, for the interval.
     *
     * @return string One of `SubscriptionType::INTERVAL_*`
     * @throws InvalidConfigException if there's no override and `subscriptionTypeId` doesn't resolve to a real `SubscriptionType`
     */
    public function getEffectiveInterval(): string {
        if ($this->interval !== null) return $this->interval;

        $type = $this->getSubscriptionType();

        if ($type === null) {
            throw new InvalidConfigException("Invalid subscription type ID: {$this->subscriptionTypeId}");
        }

        return $type->interval;
    }

    /**
     * Display-safe version of `getEffectiveAmount()` — a brand-new
     * unpublished draft legitimately has neither an override nor a
     * subscription type yet (the `required` rule is scenario-scoped away
     * from `SCENARIO_ESSENTIALS` specifically so it can be saved that
     * way), and `metadata()`/`attributeHtml()` render even then. Null
     * means "nothing to show," not an error.
     *
     * @return Money|null
     */
    private function getEffectiveAmountOrNull(): ?Money {
        if ($this->_amount === null && $this->getSubscriptionType() === null) return null;

        return $this->getEffectiveAmount();
    }

    /**
     * Display-safe version of `getEffectiveInterval()` — see
     * `getEffectiveAmountOrNull()`.
     *
     * @return string|null One of `SubscriptionType::INTERVAL_*`
     */
    private function getEffectiveIntervalOrNull(): ?string {
        if ($this->interval === null && $this->getSubscriptionType() === null) return null;

        return $this->getEffectiveInterval();
    }

    /**
     * @return array<string, array{label: string, color: Color}> Status handle => definition
     */
    public static function statuses(): array {
        return [
            self::STATUS_ACTIVE => ['label' => Craft::t('qr-payments', 'Active'), 'color' => Color::Green],
            self::STATUS_PAUSED => ['label' => Craft::t('qr-payments', 'Paused'), 'color' => Color::Orange],
            self::STATUS_CANCELLED => ['label' => Craft::t('qr-payments', 'Cancelled'), 'color' => Color::Gray],
        ];
    }

    /**
     * Same reasoning as `Payment::getStatus()`: Craft's native enabled/
     * disabled toggle stays an independent axis from this plugin-owned
     * `$status`.
     *
     * @return string|null
     */
    public function getStatus(): ?string {
        if ($this->getIsDraft() || $this->archived) return parent::getStatus();

        return $this->status;
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
     * Never hard-deletable — cancelling (`SubscriptionsService::cancel()`)
     * is the only "removal" a subscription gets, same as the plain-model
     * implementation this replaced.
     *
     * @param User $user
     * @return bool
     */
    public function canDelete(User $user): bool {
        return false;
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
            'nextDueDate' => Craft::t('qr-payments', 'Next due date'),
            'startedAt' => Craft::t('qr-payments', 'Started at'),
            'dateCreated' => Craft::t('qr-payments', 'Date created'),
        ];
    }

    /**
     * @return array
     */
    protected static function defineTableAttributes(): array {
        return [
            'email' => ['label' => Craft::t('qr-payments', 'Email')],
            'subscriptionType' => ['label' => Craft::t('qr-payments', 'Plan')],
            'amount' => ['label' => Craft::t('qr-payments', 'Amount')],
            'interval' => ['label' => Craft::t('qr-payments', 'Interval')],
            'status' => ['label' => Craft::t('qr-payments', 'Status')],
            'nextDueDate' => ['label' => Craft::t('qr-payments', 'Next due date')],
            'startedAt' => ['label' => Craft::t('qr-payments', 'Started at')],
            'actions' => ['label' => Craft::t('qr-payments', 'Actions')],
        ];
    }

    /**
     * @param string $source
     * @return string[]
     */
    protected static function defineDefaultTableAttributes(string $source): array {
        return ['email', 'subscriptionType', 'amount', 'interval', 'status', 'nextDueDate', 'actions'];
    }

    /**
     * @inheritdoc
     */
    protected function attributeHtml(string $attribute): string {
        return match ($attribute) {
            'subscriptionType' => Html::encode($this->getSubscriptionType()?->name ?? ''),
            'amount' => Html::encode(MoneyHelper::toString($this->getEffectiveAmountOrNull()) ?: '') . ($this->getAmount() ? ' ' . Html::tag('span', Craft::t('qr-payments', 'override'), ['class' => 'light']) : ''),
            'interval' => Html::encode(($effectiveInterval = $this->getEffectiveIntervalOrNull()) !== null ? (SubscriptionType::intervals()[$effectiveInterval] ?? $effectiveInterval) : '') . ($this->interval ? ' ' . Html::tag('span', Craft::t('qr-payments', 'override'), ['class' => 'light']) : ''),
            'actions' => $this->statusActionButtonsHtml(small: true),
            default => parent::attributeHtml($attribute),
        };
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
            [['email', 'subscriptionTypeId', 'startedAt'], 'required', 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]],

            [['userId'], 'integer'],
            [['userId'], 'validateOwnerExists'],

            [['email'], 'email'],
            [['email'], 'string', 'max' => 255],

            [['subscriptionTypeId'], 'integer'],
            [['subscriptionTypeId'], 'validateSubscriptionTypeExists'],

            [['amount'], 'validateAmount'],
            [['interval'], 'validateInterval'],

            [['status'], 'in', 'range' => array_keys(self::statuses())],

            [['nextDueDate', 'startedAt', 'endedAt'], 'safe'],
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
     * Only validated when an override is actually set — a blank amount
     * just means "follow the subscription type," not an error.
     *
     * @param string $attribute
     * @return void
     */
    public function validateAmount(string $attribute): void {
        $amount = $this->$attribute;

        if ($amount === null) return;

        if (!$amount->isPositive()) {
            $this->addError($attribute, Craft::t('qr-payments', 'Amount must be greater than zero.'));
        }
    }

    /**
     * Only validated when an override is actually set — a blank interval
     * just means "follow the subscription type," not an error.
     *
     * @param string $attribute
     * @return void
     */
    public function validateInterval(string $attribute): void {
        $interval = $this->$attribute;

        if ($interval === null) return;

        if (!isset(SubscriptionType::intervals()[$interval])) {
            $this->addError($attribute, Craft::t('qr-payments', 'Invalid interval.'));
        }
    }

    /**
     * @return string
     */
    public function cpEditUrl(): string {
        return UrlHelper::cpUrl("qr-payments/subscriptions/{$this->id}");
    }

    /**
     * @return array|string|null
     */
    protected function route(): array|string|null {
        return null;
    }

    /**
     * Code-only field layout (no designer route) — this plugin owns the
     * shape of a subscription's editable native attributes entirely; see
     * `SubscriptionFieldsField`.
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

        $tab->setElements([new SubscriptionFieldsField(), new SubscriptionPaymentsField()]);
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
     * @return array
     */
    protected function metadata(): array {
        return array_merge(parent::metadata(), [
            Craft::t('app', 'Status') => fn() => Cp::componentStatusLabelHtml($this),
            Craft::t('qr-payments', 'Email') => fn() => $this->email ?: false,
            Craft::t('qr-payments', 'Subscription type') => fn() => $this->getSubscriptionType()?->name ?: false,
            Craft::t('qr-payments', 'Effective amount') => fn() => MoneyHelper::toString($this->getEffectiveAmountOrNull()) ?: false,
            Craft::t('qr-payments', 'Started at') => fn() => $this->startedAt
                ? Craft::$app->getFormatter()->asDatetime($this->startedAt)
                : false,
            Craft::t('qr-payments', 'Ended at') => fn() => $this->endedAt
                ? Craft::$app->getFormatter()->asDatetime($this->endedAt)
                : false,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getAdditionalButtons(): string {
        return $this->statusActionButtonsHtml(small: false) . parent::getAdditionalButtons();
    }

    /**
     * @param bool $small
     * @return string
     */
    private function statusActionButtonsHtml(bool $small): string {
        if (!$this->id || $this->getIsUnpublishedDraft()) return '';

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$this->canSave($user)) return '';

        $buttons = [];

        if ($this->status !== self::STATUS_ACTIVE) $buttons[] = $this->statusActionButtonHtml('activate', Craft::t('qr-payments', 'Activate'), $small);
        if ($this->status === self::STATUS_ACTIVE) $buttons[] = $this->statusActionButtonHtml('pause', Craft::t('qr-payments', 'Pause'), $small);
        if ($this->status !== self::STATUS_CANCELLED) {
            $buttons[] = $this->statusActionButtonHtml('cancel', Craft::t('qr-payments', 'Cancel'), $small, Craft::t('qr-payments', 'Cancel this subscription?'));
        }

        if (empty($buttons)) return '';

        if ($small) $this->registerStatusActionsJs();

        return Html::tag('div', implode('', $buttons), ['class' => array_filter(['btngroup', $small ? 'small' : null])]);
    }

    /**
     * The large (edit-screen toolbar) version relies on `formsubmit`
     * submitting the enclosing `elements/edit` form, which already posts
     * `elementId` for free. The small (index-row) version has no such
     * enclosing form, so it's a plain JS-driven button instead — see
     * `registerStatusActionsJs()`.
     *
     * @param string $action
     * @param string $label
     * @param bool $small
     * @param string|null $confirm
     * @return string
     */
    private function statusActionButtonHtml(string $action, string $label, bool $small, ?string $confirm = null): string {
        if ($small) {
            return Html::tag('button', $label, [
                'type' => 'button',
                'class' => ['btn', 'small'],
                'data' => [
                    'qrp-subscription-action' => $action,
                    'element-id' => $this->id,
                    'confirm' => $confirm,
                ],
            ]);
        }

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
     * @return void
     */
    private function registerStatusActionsJs(): void {
        Craft::$app->getView()->registerJs(<<<JS
        (() => {
            if (window.qrPaymentsSubscriptionActionsBound) return;
            window.qrPaymentsSubscriptionActionsBound = true;

            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-qrp-subscription-action]');

                if (!trigger) return;

                event.preventDefault();

                if (trigger.dataset.confirm && !confirm(trigger.dataset.confirm)) return;

                Craft.sendActionRequest('POST', `qr-payments/subscriptions/\${trigger.dataset.qrpSubscriptionAction}`, {
                    data: { elementId: trigger.dataset.elementId },
                }).then((response) => {
                    Craft.cp.displayNotice(response.data.message);
                    window.location.reload();
                }).catch((error) => {
                    Craft.cp.displayError(error?.response?.data?.message);
                });
            });
        })();
        JS, key: 'qr-payments-subscription-actions');
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
                'amount' => MoneyHelper::toDecimal($this->getAmount()) ?: null,
                'interval' => $this->interval,
                'status' => $this->status,
                'nextDueDate' => Db::prepareDateForDb($this->nextDueDate),
                'startedAt' => Db::prepareDateForDb($this->startedAt),
                'endedAt' => Db::prepareDateForDb($this->endedAt),
            ], [
                'userId' => $this->userId,
                'email' => $this->email,
                'subscriptionTypeId' => $this->subscriptionTypeId,
                'amount' => MoneyHelper::toDecimal($this->getAmount()) ?: null,
                'interval' => $this->interval,
                'status' => $this->status,
                'nextDueDate' => Db::prepareDateForDb($this->nextDueDate),
                'startedAt' => Db::prepareDateForDb($this->startedAt),
                'endedAt' => Db::prepareDateForDb($this->endedAt),
            ]);
        }

        parent::afterSave($isNew);
    }
}
