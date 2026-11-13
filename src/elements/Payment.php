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
use yii\web\Response;

use digitalastronaut\craftqrpayments\elements\conditions\PaymentCondition;
use digitalastronaut\craftqrpayments\elements\db\PaymentQuery;
use digitalastronaut\craftqrpayments\fieldlayoutelements\StatusHistoryField;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\jobs\SendPaymentStatusChangedEmailJob;
use digitalastronaut\craftqrpayments\QrPayments;

use DateTime;

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
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_AWAITING_CONFIRMATION = 'reported';
    public const string STATUS_PARTIAL = 'partial';
    public const string STATUS_PAID = 'paid';
    public const string STATUS_OVERPAID = 'overpaid';
    public const string STATUS_EXPIRED = 'expired';
    public const string STATUS_CANCELLED = 'cancelled';
    public const string STATUS_REFUNDED = 'refunded';

    public const string HISTORY_TYPE_PAYMENT = 'payment';
    public const string HISTORY_TYPE_PAYER_REPORTED = 'payer_reported';
    public const string HISTORY_TYPE_REFUND = 'refund';
    public const string HISTORY_TYPE_CANCELLATION = 'cancellation';

    public const string CURRENCY = 'EUR';

    private ?FieldLayout $fieldLayout = null;

    public ?string $email = null;
    public ?string $beneficiaryName = null;
    public ?string $iban = null;
    public ?string $bic = null;

    private ?Money $_amount = null;

    public ?string $remittanceReference = null;
    public ?string $remittanceText = null;
    public ?string $qrCode = null;

    public string $status = self::STATUS_PENDING;

    private array $_statusHistory = [];

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
    public static function isLocalized(): bool { return false; }
    public static function hasStatuses(): bool { return true; }

    public function canDuplicate(User $user): bool { return false; }

    public function canCreateDrafts(User $user): bool { return false; }

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
            self::STATUS_PENDING => ['label' => Craft::t('qr-payments', 'Pending'), 'color' => Color::Orange],
            self::STATUS_AWAITING_CONFIRMATION => ['label' => Craft::t('qr-payments', 'Marked paid'), 'color' => Color::Cyan],
            self::STATUS_PARTIAL => ['label' => Craft::t('qr-payments', 'Partially paid'), 'color' => Color::Blue],
            self::STATUS_PAID => ['label' => Craft::t('qr-payments', 'Paid'), 'color' => Color::Teal],
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
            self::HISTORY_TYPE_PAYMENT => ['label' => Craft::t('qr-payments', 'Payment'), 'color' => Color::Teal],
            self::HISTORY_TYPE_PAYER_REPORTED => ['label' => Craft::t('qr-payments', 'Marked as paid by payer'), 'color' => Color::Cyan],
            self::HISTORY_TYPE_REFUND => ['label' => Craft::t('qr-payments', 'Refund'), 'color' => Color::Purple],
            self::HISTORY_TYPE_CANCELLATION => ['label' => Craft::t('qr-payments', 'Cancellation'), 'color' => Color::Gray],
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
            $this->status === self::STATUS_PENDING && 
            $this->dueDate !== null && 
            $this->dueDate < DateTimeHelper::currentUTCDateTime()) 
        {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    /**
     * @return bool Whether the payer can self-report this payment as paid —
     * only while a balance is still outstanding and nothing's been
     * confirmed or already self-reported.
     */
    public function canReportPaidByPayer(): bool {
        return \in_array($this->getStatus(), [self::STATUS_PENDING, self::STATUS_PARTIAL], true);
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
     * @return void
     */
    public function pushStatusHistoryEntry(string $type, ?Money $amount, ?int $userId, ?string $note = null, ?string $source = null): void {
        $history = $this->getStatusHistory();

        $history[] = [
            'type' => $type,
            'date' => DateTimeHelper::currentUTCDateTime()->format(DateTime::ATOM),
            'userId' => $userId,
            'amount' => $amount ? MoneyHelper::toDecimal($amount) : null,
            'note' => $note,
            'source' => $source,
        ];

        $this->setStatusHistory($history);
    }

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool {
        $this->status = $this->computeStatus();
        $this->syncConfirmation();

        return parent::beforeSave($isNew);
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
     * @return string
     */
    public function computeStatus(): string {
        if ($this->hasHistoryEntryOfType(self::HISTORY_TYPE_CANCELLATION)) return self::STATUS_CANCELLED;

        $netPaid = $this->netPaidAmount();
        $totalPrice = $this->getAmount();

        if ($netPaid->isPositive()) {
            if ($totalPrice === null) return self::STATUS_PARTIAL;

            return match (true) {
                $netPaid->equals($totalPrice) => self::STATUS_PAID,
                $netPaid->greaterThan($totalPrice) => self::STATUS_OVERPAID,
                default => self::STATUS_PARTIAL,
            };
        }

        if ($this->hasHistoryEntryOfType(self::HISTORY_TYPE_REFUND)) return self::STATUS_REFUNDED;

        if ($this->hasHistoryEntryOfType(self::HISTORY_TYPE_PAYER_REPORTED)) return self::STATUS_AWAITING_CONFIRMATION;

        return self::STATUS_PENDING;
    }

    /**
     * @return Money
     */
    public function netPaidAmount(): Money {
        $currency = new Currency(self::CURRENCY);
        $paid = new Money(0, $currency);
        $refunded = new Money(0, $currency);
        $parser = new DecimalMoneyParser(new ISOCurrencies());

        foreach ($this->getStatusHistory() as $entry) {
            $amount = $entry['amount'] ?? null;

            if ($amount === null || $amount === '') continue;

            $money = $parser->parse((string) $amount, $currency);

            match ($entry['type'] ?? null) {
                self::HISTORY_TYPE_PAYMENT => $paid = $paid->add($money),
                self::HISTORY_TYPE_REFUND => $refunded = $refunded->add($money),
                default => null,
            };
        }

        return $paid->subtract($refunded);
    }

    /**
     * @param string $type
     * @return bool
     */
    private function hasHistoryEntryOfType(string $type): bool {
        foreach ($this->getStatusHistory() as $entry) {
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
        $sources = [
            [
                'key' => 'all',
                'label' => Craft::t('qr-payments', 'All payments'),
            ],
            [
                'key' => 'needs-action',
                'label' => Craft::t('qr-payments', 'Needs action'),
                'criteria' => ['status' => [self::STATUS_PENDING, self::STATUS_AWAITING_CONFIRMATION, self::STATUS_EXPIRED, self::STATUS_PARTIAL, self::STATUS_OVERPAID]],
                'defaultSort' => ['dueDate', 'asc'],
            ],
            [
                'key' => 'resolved',
                'label' => Craft::t('qr-payments', 'Resolved'),
                'criteria' => ['status' => [self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_REFUNDED]],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        $sources[] = ['heading' => Craft::t('qr-payments', 'Statuses')];

        $statusCounts = static::find()->statusCounts();

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
            'beneficiaryName' => ['label' => Craft::t('qr-payments', 'Beneficiary')],
            'iban' => ['label' => Craft::t('qr-payments', 'IBAN')],
            'amount' => ['label' => Craft::t('qr-payments', 'Total price')],
            'remittanceReference' => ['label' => Craft::t('qr-payments', 'Reference')],
            'remittanceText' => ['label' => Craft::t('qr-payments', 'Description')],
            'dueDate' => ['label' => Craft::t('qr-payments', 'Due date')],
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
            'amount' => Html::encode(MoneyHelper::toString($this->getAmount()) ?: ''),
            'historyActions' => $this->historyActionButtonsHtml(small: true),
            default => parent::attributeHtml($attribute),
        };
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        return array_merge(parent::defineRules(), [
            [['email', 'beneficiaryName', 'iban', 'amount', 'remittanceReference'], 'required'],

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
     * @param string|null $source
     * @return FieldLayout[]
     */
    protected static function defineFieldLayouts(?string $source): array {
        $layout = new FieldLayout(['type' => static::class]);
        $tab = new FieldLayoutTab(['name' => Craft::t('qr-payments', 'Payment'), 'layout' => $layout]);
        $elements = [new StatusHistoryField()];

        foreach (static::getCustomFieldLayout()->getTabs() as $customTab) {
            array_push($elements, ...$customTab->getElements());
        }

        $tab->setElements($elements);
        $layout->setTabs([$tab]);

        return [$layout];
    }

    /**
     * @return FieldLayout
     */
    public static function getCustomFieldLayout(): FieldLayout {
        return Craft::$app->getFields()->getLayoutByType(static::class);
    }

    /**
     * @return FieldLayout|null
     */
    public function getFieldLayout(): ?FieldLayout {
        if ($this->fieldLayout !== null) return $this->fieldLayout;

        $this->fieldLayout = static::defineFieldLayouts(null)[0] ?? null;

        return $this->fieldLayout;
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

        $dueDate = Html::tag('div', $this->dueDateFieldHtml($static), ['class' => 'meta']);

        return $image . parent::getSidebarHtml($static) . $dueDate;
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

        return [
            'markAsPaid' => !$hasPayment && !\in_array($status, [self::STATUS_PAID, self::STATUS_REFUNDED], true),
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
            Craft::t('qr-payments', 'Email') => fn() => $this->email ?: false,
            Craft::t('qr-payments', 'Beneficiary') => fn() => $this->beneficiaryName ?: false,
            Craft::t('qr-payments', 'IBAN') => fn() => $this->iban ?: false,
            Craft::t('qr-payments', 'BIC') => fn() => $this->bic ?: false,
            Craft::t('qr-payments', 'Confirmed at') => fn() => $this->confirmedAt
                ? Craft::$app->getFormatter()->asDatetime($this->confirmedAt)
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
            $this->notifyStatusChange($isNew);

            Db::upsert(Table::QR_PAYMENTS_PAYMENTS, [
                'id' => $this->id,
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
        }

        parent::afterSave($isNew);
    }

    /**
     * @param bool $isNew
     * @return void
     */
    private function notifyStatusChange(bool $isNew): void {
        $previousStatus = $isNew ? null : (new Query())
            ->select(['status'])
            ->from(Table::QR_PAYMENTS_PAYMENTS)
            ->where(['id' => $this->id])
            ->scalar();

        if ($isNew || $previousStatus === $this->status || !$this->email) return;

        Craft::$app->getQueue()->push(new SendPaymentStatusChangedEmailJob([
            'paymentId' => $this->id,
            'previousStatus' => $previousStatus,
        ]));

        App::maxPowerCaptain();
        Craft::$app->queue->run();
    }
}