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
use craft\elements\User;
use craft\db\Query;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\enums\Color;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Localization;
use craft\helpers\MoneyHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Money\Currencies\ISOCurrencies;
use yii\base\InvalidConfigException;
use yii\web\Response;

use digitalastronaut\craftqrpayments\elements\conditions\PaymentCondition;
use digitalastronaut\craftqrpayments\elements\db\PaymentQuery;
use digitalastronaut\craftqrpayments\fieldlayoutelements\PaymentFieldsField;
use digitalastronaut\craftqrpayments\fieldlayoutelements\StatusHistoryField;
use digitalastronaut\craftqrpayments\helpers\CpDateFormatter;
use digitalastronaut\craftqrpayments\helpers\HumanDuration;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\jobs\SendPaymentCreatedEmailJob;
use digitalastronaut\craftqrpayments\jobs\SendPaymentStatusChangedEmailJob;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\QrPayments;

use DateTime;
use Throwable;

/**
 * Class Payment
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 *
 * @property array $statusHistory
 * @property Money|null $amount
 */
class Payment extends Element {
    public const string STATUS_REQUESTED = 'requested';
    public const string STATUS_AWAITING_CONFIRMATION = 'reported';
    public const string STATUS_PARTIAL = 'partial';
    public const string STATUS_PAID = 'paid';
    public const string STATUS_OVERPAID = 'overpaid';
    public const string STATUS_EXPIRED = 'expired';
    public const string STATUS_CANCELLED = 'cancelled';
    public const string STATUS_REFUNDED = 'refunded';

    public const string HISTORY_TYPE_CREATED = 'created';
    public const string HISTORY_TYPE_PAYMENT = 'payment';
    public const string HISTORY_TYPE_PAYER_REPORTED = 'payer_reported';
    public const string HISTORY_TYPE_REFUND = 'refund';
    public const string HISTORY_TYPE_CANCELLATION = 'cancellation';

    public const string CURRENCY = 'EUR';

    private ?FieldLayout $fieldLayout = null;

    public ?int $typeId = null;
    public ?int $subscriptionId = null;
    public ?int $userId = null;

    public ?string $email = null;
    public ?string $beneficiaryName = null;
    public ?string $iban = null;
    public ?string $bic = null;

    private ?Money $_amount = null;

    public ?string $remittanceReference = null;
    public ?string $remittanceText = null;
    public ?string $qrCode = null;

    public string $status = self::STATUS_REQUESTED;

    private array $_statusHistory = [];

    /** The `id` `beforeSave()`'s `HISTORY_TYPE_CREATED` push returns, read back by `notifyCreated()` once the save succeeds. */
    private ?string $pendingCreatedHistoryEntryId = null;

    public ?DateTime $dueDate = null;
    public ?DateTime $confirmedAt = null;
    public ?int $confirmedByUserId = null;

    public static function displayName(): string { return Craft::t('qr-payments', 'Payment'); }
    public static function lowerDisplayName(): string { return Craft::t('qr-payments', 'payment'); }
    public static function pluralDisplayName(): string { return Craft::t('qr-payments', 'Payments'); }
    public static function pluralLowerDisplayName(): string { return Craft::t('qr-payments', 'payments'); }
    public static function refHandle(): ?string { return 'payment'; }

    public static function trackChanges(): bool { return true; }
    public static function hasTitles(): bool { return true; }
    public static function hasUris(): bool { return true; }
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
        return $user->can('qr-payments:access-payments');
    }

    /**
     * Defaults a freshly-constructed, not-yet-typed Payment to
     * `PaymentTypesService::getDefaultPaymentType()`. Transitional:
     * `PaymentsService::createPayment()` doesn't accept an explicit type yet (see
     * PAYMENT_TYPES_AND_SUBSCRIPTIONS_PLAN.md phase 1, step 9) — once it
     * does, callers will set `typeId` themselves and this becomes a no-op
     * for them. Elements hydrated from the database already have `typeId`
     * populated before `init()` runs, so this never overrides a real value.
     *
     * @inheritdoc
     */
    public function init(): void {
        parent::init();

        $this->typeId ??= QrPayments::getInstance()->getPaymentTypes()->getDefaultPaymentType()?->id;
    }

    /**
     * Called from both `beforeValidate()` and `beforeSave()`: the former so
     * a standalone `validate()` call (e.g. the edit screen's "prevalidate"
     * pass, which runs with no save involved) doesn't flag these as missing;
     * the latter because `beforeSave()` actually runs *before* `validate()`
     * within the same save cycle, so `generateQrCode()` (gated on
     * `$this->firstSave`, further down in `beforeSave()`) can't rely on a
     * same-cycle `beforeValidate()` call having populated `beneficiaryName`/
     * `iban` yet — it needs them derived right there too. `??=` throughout
     * makes calling this twice in one cycle harmless.
     *
     * @return void
     */
    private function deriveFromType(): void {
        $type = $this->getType();

        $this->beneficiaryName ??= $type->getBeneficiaryName();
        $this->iban ??= $type->getIban();
        $this->bic ??= $type->getBic();
        $this->dueDate ??= DateTimeHelper::currentUTCDateTime()->modify("+{$type->defaultDueDateDays} days");
        $this->remittanceReference ??= QrPayments::getInstance()->getPayments()->generateUniqueReference($type->referencePrefix);
        $this->title = $this->remittanceReference;
    }

    /**
     * @inheritdoc
     */
    public function beforeValidate(): bool {
        $this->deriveFromType();

        return parent::beforeValidate();
    }

    /**
     * @return PaymentType
     * @throws InvalidConfigException if `typeId` doesn't resolve to a real PaymentType
     */
    public function getType(): PaymentType {
        $paymentType = $this->typeId !== null
            ? QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeById($this->typeId)
            : null;

        if ($paymentType === null) {
            throw new InvalidConfigException("Invalid payment type ID: {$this->typeId}");
        }

        return $paymentType;
    }

    /**
     * @return Subscription|null The subscription that generated this
     * payment via `SubscriptionsService::advance()`, or null for a
     * one-off payment.
     */
    public function getSubscription(): ?Subscription {
        return $this->subscriptionId !== null
            ? Subscription::find()->id($this->subscriptionId)->status(null)->one()
            : null;
    }

    /**
     * Normalizes the request-posted shape for `userId`/`subscriptionId`
     * (the element-select field's "nothing selected" placeholder quirk)
     * before Craft's generic mass assignment (`setAttributes()`) handles
     * the rest — same gotcha `Subscription::setAttributesFromRequest()`
     * works around.
     *
     * @inheritdoc
     */
    public function setAttributesFromRequest(array $values): void {
        if (\array_key_exists('userId', $values)) {
            $ids = \is_array($values['userId']) ? $values['userId'] : ($values['userId'] === '' ? [] : (array) $values['userId']);
            $values['userId'] = $ids ? (int) $ids[0] : null;
        }

        if (\array_key_exists('subscriptionId', $values)) {
            $ids = \is_array($values['subscriptionId']) ? $values['subscriptionId'] : ($values['subscriptionId'] === '' ? [] : (array) $values['subscriptionId']);
            $values['subscriptionId'] = $ids ? (int) $ids[0] : null;
        }

        parent::setAttributesFromRequest($values);
    }

    /**
     * @return User|null The linked Craft user, when one is set. Null for a
     * guest payment running on `email` alone.
     */
    public function getOwner(): ?User {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    /**
     * Prefers the owner's current account email over the `email` snapshot,
     * so notifications don't go stale if the account's address changes
     * after the payment was created. Falls back to the snapshot for a
     * guest payment — same split `Subscription::getNotificationEmail()` uses.
     *
     * @return string|null
     */
    public function getNotificationEmail(): ?string {
        return $this->getOwner()?->email ?? $this->email;
    }

    /**
     * The same "time until" notation as the element index's own "Expires
     * in" column (see `attributeHtml()`, which calls this directly),
     * reused by the payments-summary digest email so both show the
     * due date the same way.
     *
     * @return string Empty string if `dueDate` is unset.
     */
    public function getDueInLabel(): string {
        return HumanDuration::until($this->dueDate);
    }

    /**
     * @param User $user
     * @return bool
     */
    public function canView(User $user): bool {
        if (parent::canView($user)) return true;
        return $user->can('qr-payments:access-payments');
    }

    /**
     * @param User $user
     * @return bool
     */
    public function canSave(User $user): bool {
        if (parent::canSave($user)) return true;
        return $user->can('qr-payments:access-payments');
    }

    /**
     * @param User $user
     * @return bool
     */
    public function canDelete(User $user): bool {
        if (parent::canDelete($user)) return true;
        return $user->can('qr-payments:access-payments');
    }

    /**
     * @return array
     */
    public static function statuses(): array {
        return [
            self::STATUS_REQUESTED => ['label' => Craft::t('qr-payments', 'Requested'), 'color' => Color::Orange],
            self::STATUS_AWAITING_CONFIRMATION => ['label' => Craft::t('qr-payments', 'Marked paid'), 'color' => Color::Cyan],
            self::STATUS_PARTIAL => ['label' => Craft::t('qr-payments', 'Partially paid'), 'color' => Color::Blue],
            self::STATUS_PAID => ['label' => Craft::t('qr-payments', 'Paid'), 'color' => Color::Green],
            self::STATUS_OVERPAID => ['label' => Craft::t('qr-payments', 'Overpaid'), 'color' => Color::Red],
            self::STATUS_EXPIRED => ['label' => Craft::t('qr-payments', 'Expired'), 'color' => Color::Red],
            self::STATUS_CANCELLED => ['label' => Craft::t('qr-payments', 'Cancelled'), 'color' => Color::Gray],
            self::STATUS_REFUNDED => ['label' => Craft::t('qr-payments', 'Refunded'), 'color' => Color::Purple],
        ];
    }

    /**
     * @return array
     */
    public static function historyTypes(): array {
        return [
            self::HISTORY_TYPE_CREATED => ['label' => Craft::t('qr-payments', 'Requested'), 'color' => Color::Orange],
            self::HISTORY_TYPE_PAYMENT => ['label' => Craft::t('qr-payments', 'Paid'), 'color' => Color::Green],
            self::HISTORY_TYPE_PAYER_REPORTED => ['label' => Craft::t('qr-payments', 'Marked paid'), 'color' => Color::Cyan],
            self::HISTORY_TYPE_REFUND => ['label' => Craft::t('qr-payments', 'Refunded'), 'color' => Color::Purple],
            self::HISTORY_TYPE_CANCELLATION => ['label' => Craft::t('qr-payments', 'Cancelled'), 'color' => Color::Gray],
        ];
    }

    /**
     * @return Money|null
     */
    public function getAmount(): ?Money {
        return $this->_amount;
    }

    /**
     * @param Money|array|string|int|float|null $value
     * @return void
     */
    public function setAmount(Money|array|string|int|float|null $value): void {
        $this->_amount = self::parseMoney($value);
    }

    /**
     * @param Money|array|string|int|float|null $value
     * @return Money|null
     */
    public static function parseMoney(Money|array|string|int|float|null $value): ?Money {
        if ($value instanceof Money || $value === null) return $value;

        if (\is_array($value)) {
            if (($value['value'] ?? '') === '') return null;

            $value = Localization::normalizeNumber($value['value'], $value['locale'] ?? null);
        } elseif (\is_float($value) || is_int($value)) {
            $value = number_format((float) $value, 2, '.', '');
        }

        return (new DecimalMoneyParser(new ISOCurrencies()))->parse((string) $value, new Currency(self::CURRENCY));
    }

    /**
     * @return string|null
     */
    public function getStatus(): ?string {
        if ($this->getIsDraft() || $this->archived) return parent::getStatus();

        if (
            $this->status === self::STATUS_REQUESTED &&
            $this->dueDate !== null &&
            $this->dueDate < DateTimeHelper::currentUTCDateTime())
        {
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
     * @return bool Whether the payer can self-report this payment as paid —
     * only while a balance is still outstanding and nothing's been
     * confirmed or already self-reported.
     */
    public function canReportPaidByPayer(): bool {
        return \in_array($this->getStatus(), [self::STATUS_REQUESTED, self::STATUS_PARTIAL], true);
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
     * @param string $type
     * @param Money|null $amount
     * @param int|null $userId
     * @param string|null $note
     * @param string|null $source Fingerprint of the bank-statement row this
     * entry was auto-applied from (see `TransactionsExportParser`), so a
     * later import covering the same transaction again can recognize it
     * and skip it instead of double-applying. Left null for entries added
     * manually through the CP — those can never collide with a fingerprint.
     * @return string The new entry's `id`, so a caller that's about to send
     * a notification email for this exact entry (see `notifyCreated()`/
     * `notifyStatusChange()`) can tag that email's `EmailLogService` row
     * with it, linking the two back together in the CP.
     */
    public function pushStatusHistoryEntry(string $type, ?Money $amount, ?int $userId, ?string $note = null, ?string $source = null): string {
        $history = $this->getStatusHistory();

        $id = StringHelper::UUID();

        $history[] = [
            'id' => $id,
            'type' => $type,
            'date' => DateTimeHelper::currentUTCDateTime()->format(DateTime::ATOM),
            'userId' => $userId,
            'amount' => $amount ? MoneyHelper::toDecimal($amount) : null,
            'note' => $note,
            'source' => $source,
        ];

        $this->setStatusHistory($history);

        return $id;
    }

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool {
        // `beforeSave()` runs *before* `validate()` within the same save
        // cycle, so this can't rely on a same-cycle `beforeValidate()` call
        // having already derived `beneficiaryName`/`iban`/etc — see
        // `deriveFromType()`'s docblock.
        $this->deriveFromType();

        // `$this->firstSave` (set by Craft core — see `Elements::saveElement()`)
        // is true exactly once: the save that turns this from a draft (or a
        // request-scoped `new Payment()`, for `PaymentsService::createPayment()`)
        // into the real, canonical payment — whether that's the "apply draft"
        // step of the native CP create flow, or a direct console/queue save.
        // By now `amount` is either already validated (the CP draft flow's
        // apply step) or set directly by the caller (`createPayment()`), so
        // this is the right — and only — moment to generate the QR code and
        // fire the "payment created" notification (in `afterSave()`).
        if ($this->firstSave) {
            if (!$this->generateQrCode()) return false;

            // So the status history timeline shows how this payment came
            // to be `requested` instead of starting blank — `computeStatus()`
            // still falls back to `requested` on its own either way, this
            // entry doesn't change that; it's a record of the event, not
            // what decides the status.
            $this->pendingCreatedHistoryEntryId = $this->pushStatusHistoryEntry(self::HISTORY_TYPE_CREATED, null, null);
        }

        $this->status = $this->computeStatus();
        $this->syncConfirmation();

        return parent::beforeSave($isNew);
    }

    /**
     * @return bool false if the EPC QR code couldn't be generated (e.g. invalid beneficiary/IBAN/BIC settings), aborting the save with a validation error
     */
    private function generateQrCode(): bool {
        try {
            $this->qrCode = QrPayments::getInstance()->getEpcQr()->generateQrCode(
                beneficiaryName: $this->beneficiaryName,
                iban: $this->iban,
                amount: (float) MoneyHelper::toDecimal($this->getAmount()),
                bic: $this->bic,
                remittanceReference: $this->remittanceReference,
            )->getString();

            return true;
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);
            $this->addError('amount', Craft::t('qr-payments', 'Could not generate the payment QR code: {message}', ['message' => $e->getMessage()]));

            return false;
        }
    }

    /**
     * Keeps `confirmedAt`/`confirmedByUserId` in sync with `status`: stamped
     * from the payment entry that completed the amount when the status
     * becomes paid, cleared again if a refund or cancellation later moves
     * the status away from paid.
     *
     * @return void
     */
    private function syncConfirmation(): void {
        if ($this->status !== self::STATUS_PAID) {
            $this->confirmedAt = null;
            $this->confirmedByUserId = null;

            return;
        }

        $entry = $this->latestHistoryEntryOfType(self::HISTORY_TYPE_PAYMENT);

        if ($entry === null) return;

        $this->confirmedAt = DateTimeHelper::toDateTime($entry['date'] ?? null) ?: null;
        $this->confirmedByUserId = $entry['userId'] ?? null;
    }

    /**
     * @param array[]|null $history Defaults to the full, current
     * `getStatusHistory()` — pass a prefix of it (e.g. the first `$n`
     * entries) to get the status that *resulted* from just those entries
     * instead, the way `StatusHistoryField` does to label each row with
     * the status it left the payment in rather than the generic kind of
     * action it was.
     * @return string
     */
    public function computeStatus(?array $history = null): string {
        if ($this->hasHistoryEntryOfType(self::HISTORY_TYPE_CANCELLATION, $history)) return self::STATUS_CANCELLED;

        $netPaid = $this->netPaidAmount($history);
        $totalPrice = $this->getAmount();

        if ($netPaid->isPositive()) {
            if ($totalPrice === null) return self::STATUS_PARTIAL;

            return match (true) {
                $netPaid->equals($totalPrice) => self::STATUS_PAID,
                $netPaid->greaterThan($totalPrice) => self::STATUS_OVERPAID,
                default => self::STATUS_PARTIAL,
            };
        }

        if ($this->hasHistoryEntryOfType(self::HISTORY_TYPE_REFUND, $history)) return self::STATUS_REFUNDED;

        if ($this->hasHistoryEntryOfType(self::HISTORY_TYPE_PAYER_REPORTED, $history)) return self::STATUS_AWAITING_CONFIRMATION;

        return self::STATUS_REQUESTED;
    }

    /**
     * @param array[]|null $history See `computeStatus()`'s own `$history` param.
     * @return Money
     */
    public function netPaidAmount(?array $history = null): Money {
        return $this->getGrossPaidAmount($history)->subtract($this->getRefundedAmount($history));
    }

    /**
     * @param array[]|null $history See `computeStatus()`'s own `$history` param.
     * @return Money Everything ever recorded as paid, before any refunds
     * are netted out — see `netPaidAmount()` for the figure that matters
     * for status/reconciliation, and `getRefundedAmount()` for the other
     * half of that subtraction.
     */
    public function getGrossPaidAmount(?array $history = null): Money {
        return $this->sumHistoryAmountsOfType(self::HISTORY_TYPE_PAYMENT, $history);
    }

    /**
     * @param array[]|null $history See `computeStatus()`'s own `$history` param.
     * @return Money Everything ever recorded as refunded.
     */
    public function getRefundedAmount(?array $history = null): Money {
        return $this->sumHistoryAmountsOfType(self::HISTORY_TYPE_REFUND, $history);
    }

    /**
     * @param string $type One of the `HISTORY_TYPE_*` constants.
     * @param array[]|null $history Defaults to the full, current `getStatusHistory()` — see `computeStatus()`'s own `$history` param.
     * @return Money
     */
    private function sumHistoryAmountsOfType(string $type, ?array $history = null): Money {
        $currency = new Currency(self::CURRENCY);
        $sum = new Money(0, $currency);
        $parser = new DecimalMoneyParser(new ISOCurrencies());

        foreach ($history ?? $this->getStatusHistory() as $entry) {
            if (($entry['type'] ?? null) !== $type) continue;

            $amount = $entry['amount'] ?? null;

            if ($amount === null || $amount === '') continue;

            $sum = $sum->add($parser->parse((string) $amount, $currency));
        }

        return $sum;
    }

    /**
     * @return Money The balance still owed — the total price minus
     * whatever's been paid net of refunds. Floors at zero rather than
     * going negative once nothing more is owed (e.g. once paid in full),
     * and falls back to zero for a flexible-amount payment with no fixed
     * total yet.
     */
    public function getRemainingAmount(): Money {
        $currency = new Currency(self::CURRENCY);
        $total = $this->getAmount() ?? new Money(0, $currency);
        $remaining = $total->subtract($this->netPaidAmount());

        return $remaining->isNegative() ? new Money(0, $currency) : $remaining;
    }

    /**
     * @return Money How much more than the total price has been paid, net
     * of refunds — the amount still owed back to the payer. Floors at
     * zero once there's nothing left to refund (e.g. the overpayment has
     * already been refunded, dropping this back to `paid`).
     */
    public function getOverpaidAmount(): Money {
        $currency = new Currency(self::CURRENCY);
        $total = $this->getAmount() ?? new Money(0, $currency);
        $over = $this->netPaidAmount()->subtract($total);

        return $over->isNegative() ? new Money(0, $currency) : $over;
    }

    /**
     * @return string|null Same shape as `getQrCodeDataUri()`, but for a
     * `partial` payment it's regenerated here for the outstanding balance
     * rather than returning the `qrCode` column — that's a static blob
     * written once at creation for the amount originally requested (see
     * `generateQrCode()`), and never updates as partial payments come in,
     * so scanning it would still prefill a banking app with the original
     * total instead of what's actually left to pay.
     */
    public function getPayableQrCodeDataUri(): ?string {
        if ($this->getStatus() !== self::STATUS_PARTIAL) return $this->getQrCodeDataUri();

        try {
            return QrPayments::getInstance()->getEpcQr()->generateQrCode(
                beneficiaryName: $this->beneficiaryName,
                iban: $this->iban,
                amount: (float) MoneyHelper::toDecimal($this->getRemainingAmount()),
                bic: $this->bic,
                remittanceReference: $this->remittanceReference,
            )->getDataUri();
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);

            return $this->getQrCodeDataUri();
        }
    }

    /**
     * @param string $type
     * @param array[]|null $history Defaults to the full, current `getStatusHistory()` — see `computeStatus()`'s own `$history` param.
     * @return bool
     */
    private function hasHistoryEntryOfType(string $type, ?array $history = null): bool {
        foreach ($history ?? $this->getStatusHistory() as $entry) {
            if (($entry['type'] ?? null) === $type) return true;
        }

        return false;
    }

    /**
     * @param string $type
     * @return array|null The most recent history entry of this type, or null if there isn't one.
     */
    private function latestHistoryEntryOfType(string $type): ?array {
        $match = null;

        foreach ($this->getStatusHistory() as $entry) {
            if (($entry['type'] ?? null) === $type) $match = $entry;
        }

        return $match;
    }

    /**
     * @return PaymentQuery
     */
    public static function find(): ElementQueryInterface {
        return Craft::createObject(PaymentQuery::class, [static::class]);
    }

    /**
     * @return PaymentCondition
     */
    public static function createCondition(): ElementConditionInterface {
        return Craft::createObject(PaymentCondition::class, [static::class]);
    }

    /**
     * @return string[]
     */
    protected static function defineSearchableAttributes(): array {
        return ['beneficiaryName', 'iban', 'remittanceReference', 'remittanceText'];
    }

    /**
     * @param string $context
     * @return array[]
     */
    protected static function defineSources(string $context): array {
        $statusCounts = static::find()->statusCounts();

        $needsActionStatuses = [self::STATUS_REQUESTED, self::STATUS_AWAITING_CONFIRMATION, self::STATUS_EXPIRED, self::STATUS_PARTIAL, self::STATUS_OVERPAID];
        $resolvedStatuses = [self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_REFUNDED];

        $sources = [
            [
                'key' => 'all',
                'label' => Craft::t('qr-payments', 'All payments'),
                'badgeCount' => array_sum($statusCounts),
            ],
            [
                'key' => 'needs-action',
                'label' => Craft::t('qr-payments', 'Needs action'),
                'criteria' => ['status' => $needsActionStatuses],
                'defaultSort' => ['dueDate', 'asc'],
                'badgeCount' => array_sum(array_intersect_key($statusCounts, array_flip($needsActionStatuses))),
            ],
            [
                'key' => 'resolved',
                'label' => Craft::t('qr-payments', 'Resolved'),
                'criteria' => ['status' => $resolvedStatuses],
                'defaultSort' => ['dateCreated', 'desc'],
                'badgeCount' => array_sum(array_intersect_key($statusCounts, array_flip($resolvedStatuses))),
            ],
        ];

        $sources[] = ['heading' => Craft::t('qr-payments', 'Statuses')];

        foreach (static::statuses() as $handle => $def) {
            $sources[] = [
                'key' => "status:{$handle}",
                'label' => $def['label'],
                'status' => $def['color']->value,
                'badgeCount' => $statusCounts[$handle] ?? 0,
                'criteria' => ['status' => $handle],
            ];
        }

        $sources[] = ['heading' => Craft::t('qr-payments', 'History')];

        $grouped = [];

        foreach (static::find()->groupedByMonth() as $row) {
            $year = (int) $row['year'];
            $month = (int) $row['month'];

            $grouped[$year][$month] = [
                'label' => StringHelper::upperCaseFirst(Craft::$app->getFormatter()->asDate("{$year}-{$month}-01", 'MMMM')),
                'count' => $row['count'],
            ];
        }

        krsort($grouped);

        foreach ($grouped as $year => $months) {
            krsort($months);

            $monthSources = [];
            $yearCount = 0;

            foreach ($months as $monthNumber => $month) {
                $from = sprintf('%04d-%02d-01', $year, $monthNumber);
                $to = date('Y-m-d', strtotime("$from +1 month"));

                $monthSources[] = [
                    'key' => "month:{$year}-{$monthNumber}",
                    'label' => $month['label'],
                    'badgeCount' => $month['count'],
                    'criteria' => ['dateCreated' => ['and', ">= {$from}", "< {$to}"]],
                    'defaultSort' => ['dateCreated', 'desc'],
                ];

                $yearCount += $month['count'];
            }

            $sources[] = [
                'key' => "year:{$year}",
                'label' => (string) $year,
                'badgeCount' => $yearCount,
                'criteria' => ['dateCreated' => ['and', ">= {$year}-01-01", '< ' . ($year + 1) . '-01-01']],
                'defaultSort' => ['dateCreated', 'desc'],
                'nested' => $monthSources,
            ];
        }

        return $sources;
    }

    /**
     * @param string $source
     * @return array
     */
    protected static function defineActions(string $source): array {
        return parent::defineActions($source);
    }

    /**
     * @return array
     */
    protected static function defineSortOptions(): array {
        return [
            'title' => Craft::t('qr-payments', 'Title'),
            'amount' => Craft::t('qr-payments', 'Total price'),
            'dueDate' => Craft::t('qr-payments', 'Due date'),
            'confirmedAt' => Craft::t('qr-payments', 'Confirmed at'),
            'dateCreated' => Craft::t('qr-payments', 'Date created'),
        ];
    }

    /**
     * @return array
     */
    protected static function defineTableAttributes(): array {
        return [
            'status' => ['label' => Craft::t('qr-payments', 'Status')],
            'owner' => ['label' => Craft::t('qr-payments', 'Owner')],
            'type' => ['label' => Craft::t('qr-payments', 'Type')],
            'beneficiaryName' => ['label' => Craft::t('qr-payments', 'Beneficiary')],
            'iban' => ['label' => Craft::t('qr-payments', 'IBAN')],
            'amount' => ['label' => Craft::t('qr-payments', 'Total price')],
            'remittanceReference' => ['label' => Craft::t('qr-payments', 'Reference')],
            'remittanceText' => ['label' => Craft::t('qr-payments', 'Description')],
            'dueDate' => ['label' => Craft::t('qr-payments', 'Expires in')],
            'confirmedAt' => ['label' => Craft::t('qr-payments', 'Confirmed at')],
            'historyActions' => ['label' => Craft::t('qr-payments', 'Actions')],
        ];
    }

    /**
     * @param string $source
     * @return string[]
     */
    protected static function defineDefaultTableAttributes(string $source): array {
        return [
            'status',
            'owner',
            'type',
            'amount',
            'dueDate',
            'historyActions',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function attributeHtml(string $attribute): string {
        return match ($attribute) {
            'owner' => ($owner = $this->getOwner()) ? Cp::elementChipHtml($owner) : Html::encode($this->email),
            'type' => Cp::chipHtml($this->getType(), ['hyperlink' => true]),
            'amount' => Html::encode(MoneyHelper::toString($this->getAmount()) ?: ''),
            'historyActions' => $this->historyActionButtonsHtml(small: true),
            // `dueDate` shows the "Expires in" duration (`getDueInLabel()`)
            // rather than the date itself — same reasoning
            // `Subscription::validForLabel()` shows "Valid for" instead of
            // its own "Next due date" directly. `confirmedAt` stays a
            // plain date (when something was confirmed is a fact, not a
            // countdown) — overridden rather than left to fall through to
            // `parent::attributeHtml()`, which would otherwise format it
            // in the install's locale-dependent `short` style.
            'dueDate' => Html::encode($this->getDueInLabel()),
            'confirmedAt' => Html::encode(CpDateFormatter::format($this->confirmedAt)),
            default => parent::attributeHtml($attribute),
        };
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        return array_merge(parent::defineRules(), [
            // Excludes SCENARIO_ESSENTIALS — same reasoning as
            // `Subscription::defineRules()` — so the very first save of a
            // brand-new unpublished draft succeeds before the payer/operator
            // has entered either one yet.
            [['amount'], 'required', 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]],

            // Only required for a guest payment — once there's a linked
            // `userId`, that account's own address is what notifications
            // actually go to (see `getNotificationEmail()`), so `email` is
            // just a fallback snapshot at that point, not something that
            // has to be filled in.
            [['email'], 'required', 'when' => fn(self $model) => $model->userId === null, 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]],

            // Always required, but never user-entered: `beforeValidate()`
            // derives all of these from the `PaymentType` (or, for
            // `remittanceReference`, generates one) before this rule ever runs.
            [['typeId', 'beneficiaryName', 'iban', 'remittanceReference'], 'required'],

            [['typeId'], 'integer'],
            [['subscriptionId'], 'integer'],
            [['subscriptionId'], 'validateSubscriptionExists'],
            [['userId'], 'integer'],
            [['userId'], 'validateOwnerExists'],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 255],
            [['beneficiaryName'], 'string', 'max' => 70],
            [['iban'], 'string', 'max' => 34],
            [['bic'], 'string', 'max' => 11],
            [['remittanceReference'], 'string', 'max' => 35],
            [['remittanceText'], 'string', 'max' => 140],

            [['remittanceReference'], 'validateRemittanceReferenceIsUnique'],

            [['amount'], 'validateAmount'],
            [['statusHistory'], 'validateStatusHistory'],

            [['dueDate', 'confirmedAt'], 'safe'],
            [['confirmedByUserId'], 'integer'],
        ]);
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validateAmount(string $attribute): void {
        $amount = $this->$attribute;

        if ($amount === null) return;

        $min = new Money(1, new Currency(self::CURRENCY));
        $max = new Money(99999999999, new Currency(self::CURRENCY));

        if ($amount->lessThan($min) || $amount->greaterThan($max)) {
            $this->addError($attribute, Craft::t('qr-payments', 'Amount must be between {min} and {max}.', [
                'min' => MoneyHelper::toString($min),
                'max' => MoneyHelper::toString($max),
            ]));
        }
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
    public function validateSubscriptionExists(string $attribute): void {
        $id = $this->$attribute;

        if ($id === null) return;

        if (!Subscription::find()->id($id)->status(null)->exists()) {
            $this->addError($attribute, Craft::t('qr-payments', 'Could not find the selected subscription.'));
        }
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validateStatusHistory(string $attribute): void {
        $netPaid = $this->netPaidAmount();

        if ($netPaid->isNegative()) {
            $this->addError($attribute, Craft::t('qr-payments', 'Refunds can’t exceed recorded payments (currently over by {amount}).', [
                'amount' => MoneyHelper::toString($netPaid->absolute()),
            ]));
        }
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validateRemittanceReferenceIsUnique(string $attribute): void {
        if ($this->$attribute === null) return;

        $query = self::find()->remittanceReference($this->$attribute);

        if ($this->id) $query->id(['not', $this->id]);

        if ($query->exists()) {
            $this->addError($attribute, Craft::t('qr-payments', 'Reference "{value}" is already in use.', [
                'value' => $this->$attribute,
            ]));
        }
    }

    /**
     * @return string
     */
    public function cpEditUrl(): string {
        return UrlHelper::cpUrl("qr-payments/payments/{$this->id}");
    }

    /**
     * Without this, `ElementHelper::postEditUrl()` falls back to the CP's
     * `postCpLoginRedirect` setting (`entries` by default) once a save
     * posts no `redirect` param of its own — exactly the native "New
     * payment" draft-publish flow, which never sets one.
     *
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string {
        return $this->cpEditUrl();
    }

    /**
     * @return string|null
     */
    public function getUrl(): ?string {
        if (!$this->id) return null;

        $route = QrPayments::getInstance()->getSettings()->paymentRoute;

        return UrlHelper::siteUrl("{$route}/{$this->uid}/pay");
    }

    public function getPreviewUrl(): ?string {
        return $this->getUrl();
    }

    /**
     * @return array|string|null
     */
    protected function route(): array|string|null {
        return null;
    }

    /**
     * One rendered layout per PaymentType — each is that type's custom
     * field layout with the native `StatusHistoryField` prepended, same
     * shape `getFieldLayout()` builds for a single instance below.
     *
     * @param string|null $source
     * @return FieldLayout[]
     */
    protected static function defineFieldLayouts(?string $source): array {
        $paymentTypes = QrPayments::getInstance()->getPaymentTypes()->getAllPaymentTypes();

        return array_map(
            static fn(PaymentType $paymentType) => self::buildFieldLayout($paymentType->getFieldLayout()),
            $paymentTypes,
        );
    }

    /**
     * @param FieldLayout $customLayout A PaymentType's own (custom-fields-only) layout.
     * @return FieldLayout The full rendered layout: `PaymentFieldsField` (email/amount —
     * only while still an unpublished draft) + `StatusHistoryField` + that type's custom fields.
     */
    private static function buildFieldLayout(FieldLayout $customLayout): FieldLayout {
        $layout = new FieldLayout(['type' => static::class]);
        $tab = new FieldLayoutTab(['name' => Craft::t('qr-payments', 'Payment'), 'layout' => $layout]);
        $elements = [new PaymentFieldsField(), new StatusHistoryField()];

        foreach ($customLayout->getTabs() as $customTab) {
            array_push($elements, ...$customTab->getElements());
        }

        $tab->setElements($elements);
        $layout->setTabs([$tab]);

        return $layout;
    }

    /**
     * @return FieldLayout|null
     */
    public function getFieldLayout(): ?FieldLayout {
        if ($this->fieldLayout !== null) return $this->fieldLayout;

        return $this->fieldLayout = self::buildFieldLayout($this->getType()->getFieldLayout());
    }

    /**
     * @param bool $static
     * @return string
     */
    public function getSidebarHtml(bool $static): string {
        $dataUri = $this->getQrCodeDataUri();

        $image = $dataUri === null ? '' : Html::tag('div', Html::tag('img', '', [
            'src' => $dataUri,
            'alt' => Craft::t('qr-payments', 'Payment QR code'),
            'style' => 'display: block; width: 100%; max-width: 160px; padding-block: 24px;',
        ]), ['class' => 'meta']);

        // One `.meta` box with the subscription/owner/contact/due-date
        // fields stacked as rows inside it — same grouping Craft's own
        // native sidebar boxes (e.g. the "Status" box) use, rather than
        // four separate boxes.
        $ownerGroup = Html::tag('div', $this->subscriptionFieldHtml($static) . $this->ownerFieldHtml($static) . $this->emailFieldHtml($static) . $this->dueDateFieldHtml($static), ['class' => 'meta']);

        return $image . parent::getSidebarHtml($static) . $ownerGroup;
    }

    /**
     * @return array{markAsPaid: bool, addPayment: bool, refund: bool, cancel: bool}
     */
    private function availableHistoryActions(): array {
        $status = $this->getStatus();
        $hasPayment = $this->hasHistoryEntryOfType(self::HISTORY_TYPE_PAYMENT);

        if ($status === self::STATUS_CANCELLED) {
            return ['markAsPaid' => false, 'addPayment' => false, 'refund' => $hasPayment, 'cancel' => false];
        }

        // Fully settled — nothing left to add, mark as paid, or cancel;
        // the only thing that still makes sense is undoing it.
        if ($status === self::STATUS_PAID) {
            return ['markAsPaid' => false, 'addPayment' => false, 'refund' => true, 'cancel' => false];
        }

        return [
            'markAsPaid' => !$hasPayment && $status !== self::STATUS_REFUNDED,
            'addPayment' => true,
            'refund' => $hasPayment,
            'cancel' => true,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getAdditionalButtons(): string {
        return $this->historyActionButtonsHtml(small: false) . parent::getAdditionalButtons();
    }

    /**
     * @param bool $small
     * @return string
     */
    public function historyActionButtonsHtml(bool $small): string {
        if (!$this->id) return '';

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$this->canSave($user)) return '';

        $actions = $this->availableHistoryActions();
        $buttons = [];

        if ($actions['markAsPaid']) $buttons[] = $small ? $this->markAsPaidRowButtonHtml() : $this->markAsPaidButtonHtml();
        if ($actions['addPayment']) $buttons[] = $this->historyEntryButtonHtml(self::HISTORY_TYPE_PAYMENT, Craft::t('qr-payments', 'Add payment'), $small);
        if ($actions['refund']) $buttons[] = $this->historyEntryButtonHtml(self::HISTORY_TYPE_REFUND, Craft::t('qr-payments', 'Refund'), $small);
        if ($actions['cancel']) $buttons[] = $this->historyEntryButtonHtml(self::HISTORY_TYPE_CANCELLATION, Craft::t('qr-payments', 'Cancel'), $small);

        if (empty($buttons)) return '';

        $this->registerHistoryActionsJs();

        return Html::tag('div', implode('', $buttons), ['class' => array_filter(['btngroup', $small ? 'small' : null])]);
    }

    /**
     * @return string
     */
    private function markAsPaidButtonHtml(): string {
        return Html::tag('button', Craft::t('qr-payments', 'Mark as paid'), [
            'type' => 'button',
            'class' => ['btn', 'formsubmit'],
            'data' => [
                'action' => 'qr-payments/payments/mark-as-paid',
                'confirm' => Craft::t('qr-payments', 'Mark this payment as fully paid?'),
            ],
        ]);
    }

    /**
     * @return string
     */
    private function markAsPaidRowButtonHtml(): string {
        return Html::tag('button', Craft::t('qr-payments', 'Mark as paid'), [
            'type' => 'button',
            'class' => ['btn', 'small'],
            'data' => [
                'qrp-mark-as-paid' => '1',
                'element-id' => $this->id,
                'confirm' => Craft::t('qr-payments', 'Mark this payment as fully paid?'),
            ],
        ]);
    }

    /**
     * @param string $type 
     * @param string $label
     * @param bool $small 
     * @return string
     */
    private function historyEntryButtonHtml(string $type, string $label, bool $small = false): string {
        return Html::tag('button', $label, [
            'type' => 'button',
            'class' => array_filter(['btn', $small ? 'small' : null]),
            'data' => [
                'qrp-history-modal' => $type,
                'element-id' => $this->id,
            ],
        ]);
    }

    /**
     * @return void
     */
    private function registerHistoryActionsJs(): void {
        Craft::$app->getView()->registerJs(<<<JS
        (() => {
            if (window.qrPaymentsHistoryActionsBound) return;
            window.qrPaymentsHistoryActionsBound = true;

            document.addEventListener('click', (event) => {
                const modalTrigger = event.target.closest('[data-qrp-history-modal]');

                if (modalTrigger) {
                    event.preventDefault();

                    const modal = new Craft.CpModal('qr-payments/payments/history-entry-modal', {
                        params: {
                            elementId: modalTrigger.dataset.elementId,
                            type: modalTrigger.dataset.qrpHistoryModal,
                        },
                    });

                    modal.on('submit', () => window.location.reload());
                    return;
                }

                const markAsPaidTrigger = event.target.closest('[data-qrp-mark-as-paid]');

                if (markAsPaidTrigger) {
                    event.preventDefault();

                    if (!confirm(markAsPaidTrigger.dataset.confirm)) return;

                    Craft.sendActionRequest('POST', 'qr-payments/payments/mark-as-paid', {
                        data: { elementId: markAsPaidTrigger.dataset.elementId },
                    }).then((response) => {
                        Craft.cp.displayNotice(response.data.message);
                        window.location.reload();
                    }).catch((error) => {
                        Craft.cp.displayError(error?.response?.data?.message);
                    });
                }
            });
        })();
        JS, key: 'qr-payments-history-actions');
    }

    /**
     * @return string|null
     */
    public function getQrCodeDataUri(): ?string {
        if (!$this->qrCode) return null;

        return 'data:image/png;base64,' . base64_encode($this->qrCode);
    }

    /**
     * @inheritdoc
     */
    public function fields(): array {
        $fields = parent::fields();

        unset($fields['qrCode']);

        return $fields;
    }

    /**
     * Stacked above the owner/contact pair in the sidebar's meta fieldset
     * — was a read-only `Cp::elementChipHtml()` chip in `metadata()`
     * before, but which subscription (if any) generated this payment is
     * something an admin can legitimately need to correct (e.g. a payment
     * created standalone that should actually have been linked to one),
     * not just inspect.
     *
     * @param bool $static
     * @return string
     */
    protected function subscriptionFieldHtml(bool $static): string {
        $subscription = $this->getSubscription();

        return Cp::elementSelectFieldHtml([
            'label' => Craft::t('qr-payments', 'Subscription'),
            'id' => 'subscriptionId',
            'name' => 'subscriptionId',
            'elementType' => Subscription::class,
            'elements' => $subscription ? [$subscription] : [],
            'sources' => '*',
            'limit' => 1,
            'disabled' => $static,
            'errors' => $this->getErrors('subscriptionId'),
        ]);
    }

    /**
     * The linked-account half of the owner/contact pair — stacked above
     * `emailFieldHtml()` in the sidebar's owner fieldset. Editable for the
     * life of the payment: a guest payment can be linked to an account
     * later (e.g. once the payer registers), same as `Subscription`'s own
     * owner field.
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
     * The guest-contact half of the owner/contact pair. Only actually
     * required while there's no linked account (see `defineRules()`) —
     * once one's linked, its own address is what notifications go to
     * (`getNotificationEmail()`), so this just stays around as a fallback
     * snapshot, editable here for corrections.
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
     * @param bool $static
     * @return string
     */
    protected function dueDateFieldHtml(bool $static): string {
        return Cp::dateTimeFieldHtml([
            'id' => 'dueDate',
            'name' => 'dueDate',
            'label' => Craft::t('qr-payments', 'Due date'),
            'value' => $this->dueDate,
            'disabled' => $static,
            'errors' => $this->getErrors('dueDate'),
            'status' => $this->getAttributeStatus('dueDate'),
        ]);
    }

    /**
     * @return array
     */
    protected function metadata(): array {
        return array_merge(parent::metadata(), [
            Craft::t('app', 'Status') => fn() => Cp::componentStatusLabelHtml($this),
            Craft::t('qr-payments', 'Total price') => fn() => MoneyHelper::toString($this->getAmount()) ?: false,
            Craft::t('qr-payments', 'Amount paid') => fn() => MoneyHelper::toString($this->netPaidAmount()),
            Craft::t('qr-payments', 'Reference') => fn() => $this->remittanceReference ?: false,
            Craft::t('qr-payments', 'Beneficiary') => fn() => $this->beneficiaryName ?: false,
            Craft::t('qr-payments', 'IBAN') => fn() => $this->iban ?: false,
            Craft::t('qr-payments', 'BIC') => fn() => $this->bic ?: false,
            Craft::t('qr-payments', 'Confirmed at') => fn() => $this->confirmedAt
                ? CpDateFormatter::format($this->confirmedAt)
                : false,
            Craft::t('qr-payments', 'Confirmed by') => fn() => $this->confirmedByUserId
                ? (Craft::$app->getUsers()->getUserById($this->confirmedByUserId)?->getFriendlyName() ?: false)
                : false,
        ]);
    }

    /**
     * @param Response $response
     * @param string $containerId
     * @return void
     */
    public function prepareEditScreen(Response $response, string $containerId): void {
        $response->crumbs([
            [
                'label' => self::pluralDisplayName(),
                'url' => UrlHelper::cpUrl('qr-payments/payments'),
            ],
            [
                'label' => $this->title,
                'current' => true,
            ],
        ]);
    }

    /**
     * @param bool $isNew
     * @return void
     */
    public function afterSave(bool $isNew): void {
        if (!$this->propagating) {
            // Must read the previous status before the upsert below
            // overwrites it.
            $this->notifyStatusChange();

            Db::upsert(Table::QR_PAYMENTS_PAYMENTS, [
                'id' => $this->id,
                'typeId' => $this->typeId,
                'subscriptionId' => $this->subscriptionId,
                'userId' => $this->userId,
                'email' => $this->email,
                'beneficiaryName' => $this->beneficiaryName,
                'iban' => $this->iban,
                'bic' => $this->bic,
                'amount' => MoneyHelper::toDecimal($this->getAmount()) ?: null,
                'remittanceReference' => $this->remittanceReference,
                'remittanceText' => $this->remittanceText,
                'qrCode' => $this->qrCode,
                'status' => $this->status,
                'statusHistory' => $this->getStatusHistory(),
                'dueDate' => Db::prepareDateForDb($this->dueDate),
                'confirmedAt' => Db::prepareDateForDb($this->confirmedAt),
                'confirmedByUserId' => $this->confirmedByUserId,
            ], [
                'typeId' => $this->typeId,
                'subscriptionId' => $this->subscriptionId,
                'userId' => $this->userId,
                'email' => $this->email,
                'beneficiaryName' => $this->beneficiaryName,
                'iban' => $this->iban,
                'bic' => $this->bic,
                'amount' => MoneyHelper::toDecimal($this->getAmount()) ?: null,
                'remittanceReference' => $this->remittanceReference,
                'remittanceText' => $this->remittanceText,
                'qrCode' => $this->qrCode,
                'status' => $this->status,
                'statusHistory' => $this->getStatusHistory(),
                'dueDate' => Db::prepareDateForDb($this->dueDate),
                'confirmedAt' => Db::prepareDateForDb($this->confirmedAt),
                'confirmedByUserId' => $this->confirmedByUserId,
            ]);

            // Must run after the upsert above: `SendPaymentCreatedEmailJob`
            // re-fetches the payment from the DB rather than using this
            // in-memory instance, so it needs the just-generated `qrCode`
            // (and everything else) to already be persisted.
            if ($this->firstSave) $this->notifyCreated();

            // Also after the upsert: re-evaluates every payment on this
            // subscription, which needs this one's just-saved status
            // already in the DB, not the in-memory value.
            $this->maybeActivateSubscription();
        }

        parent::afterSave($isNew);
    }

    /**
     * When this payment is linked to a subscription and effectively
     * `paid`/`overpaid`, (re-)activates that subscription once every
     * payment linked to it is also `paid`/`overpaid`. Runs on every save
     * of a subscription-linked payment, not just a transition into paid
     * status — there's no need to tell those apart, since setting an
     * already-`active` subscription to `active` again (the ordinary
     * renewal-paid case) is harmless.
     *
     * Deliberately skips a `cancelled` subscription: an admin-initiated
     * cancellation shouldn't get silently reversed just because a late
     * payment cleared.
     *
     * @return void
     */
    private function maybeActivateSubscription(): void {
        if ($this->subscriptionId === null) return;
        if (!\in_array($this->getStatus(), [self::STATUS_PAID, self::STATUS_OVERPAID], true)) return;

        $subscription = $this->getSubscription();

        if ($subscription === null) return;
        if (!\in_array($subscription->status, [Subscription::STATUS_REQUESTED, Subscription::STATUS_ACTIVE], true)) return;

        $siblingPayments = self::find()->subscriptionId($this->subscriptionId)->status(null)->all();

        foreach ($siblingPayments as $siblingPayment) {
            if (!\in_array($siblingPayment->getStatus(), [self::STATUS_PAID, self::STATUS_OVERPAID], true)) return;
        }

        QrPayments::getInstance()->getSubscriptions()->activate($subscription);
    }

    /**
     * Uses `$this->firstSave` rather than the generic Craft-level `$isNew`
     * flag passed into `afterSave()`: a payment created through the native
     * draft flow already has an element row (and so an element-level
     * `$isNew = false`) by the time it's actually created — see
     * `beforeSave()` for the full explanation of `firstSave`.
     *
     * @return void
     */
    private function notifyStatusChange(): void {
        if ($this->firstSave) return;

        $previousStatus = (new Query())
            ->select(['status'])
            ->from(Table::QR_PAYMENTS_PAYMENTS)
            ->where(['id' => $this->id])
            ->scalar();

        if ($previousStatus === $this->status || !$this->email) return;

        $latestEntry = end($this->_statusHistory) ?: null;

        Craft::$app->getQueue()->push(new SendPaymentStatusChangedEmailJob([
            'paymentId' => $this->id,
            'previousStatus' => $previousStatus,
            'historyEntryId' => $latestEntry['id'] ?? null,
        ]));

        App::maxPowerCaptain();
        Craft::$app->queue->run();
    }

    /**
     * @return void
     */
    private function notifyCreated(): void {
        Craft::$app->getQueue()->push(new SendPaymentCreatedEmailJob([
            'paymentId' => $this->id,
            'historyEntryId' => $this->pendingCreatedHistoryEntryId,
        ]));

        App::maxPowerCaptain();
        Craft::$app->queue->run();
    }
}