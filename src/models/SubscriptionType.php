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

namespace digitalastronaut\craftqrpayments\models;

use Craft;
use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\web\View;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\QrPayments;

use Carbon\Carbon;
use DateTime;
use InvalidArgumentException;
use Money\Money;

/**
 * Class SubscriptionType
 *
 * The shared plan definition every `Subscription` enrolled in it
 * references, instead of carrying its own copy of the same data — the
 * same relationship `PaymentType` has to `Payment`. Holds the price/
 * cadence (`$amount`/`$interval`) and which `PaymentType` its generated
 * payments use for beneficiary/IBAN/custom fields/templates.
 *
 * Every subscriber on a plan is charged the same amount, on the same
 * cadence — there is no per-subscription override — so changing
 * `$amount`/`$interval` here immediately applies to every subscriber on
 * their next cycle.
 *
 * Implements `Chippable`/`CpEditable` purely so `Cp::chipHtml()` can
 * render it — e.g. `Subscription`'s own "Type" column — the same
 * hyperlinked-chip look a related element would get, even though this is
 * a plain model, not an element.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class SubscriptionType extends Model implements Chippable, CpEditable {
    public const string INTERVAL_DAILY = 'daily';
    public const string INTERVAL_WEEKLY = 'weekly';
    public const string INTERVAL_BIWEEKLY = 'biweekly';
    public const string INTERVAL_MONTHLY = 'monthly';
    public const string INTERVAL_QUARTERLY = 'quarterly';
    public const string INTERVAL_YEARLY = 'yearly';

    public ?int $id = null;
    public ?string $uid = null;

    public string $name = '';
    public string $handle = '';
    public ?int $paymentTypeId = null;

    private ?Money $_amount = null;

    public string $interval = self::INTERVAL_MONTHLY;

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_subscriptionRequested`, when set.
     */
    public string $subscriptionRequestedTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_subscriptionCancelled`, when set.
     */
    public string $subscriptionCancelledTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_subscriptionExpired`, when set.
     */
    public string $subscriptionExpiredTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_subscriptionActivated`, when set.
     */
    public string $subscriptionActivatedTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_subscriptionExpiring`, when set.
     */
    public string $subscriptionExpiringTemplate = '';

    /**
     * @return array<string, string> Interval handle => label
     */
    public static function intervals(): array {
        return [
            self::INTERVAL_DAILY => Craft::t('qr-payments', 'Daily'),
            self::INTERVAL_WEEKLY => Craft::t('qr-payments', 'Weekly'),
            self::INTERVAL_BIWEEKLY => Craft::t('qr-payments', 'Bi-weekly'),
            self::INTERVAL_MONTHLY => Craft::t('qr-payments', 'Monthly'),
            self::INTERVAL_QUARTERLY => Craft::t('qr-payments', 'Quarterly'),
            self::INTERVAL_YEARLY => Craft::t('qr-payments', 'Yearly'),
        ];
    }

    /**
     * One effective interval after `$from` — "no overflow" for month-based
     * units, so e.g. Jan 31 + 1 month lands on the last day of February
     * instead of rolling over into March. Shared by
     * `Subscription::getCoverageEnd()` and `SubscriptionsService::advance()`
     * so the two can never compute this differently.
     *
     * @param DateTime $from
     * @param string $interval One of self::INTERVAL_*
     * @return DateTime
     * @throws InvalidArgumentException if `$interval` isn't a recognized value
     */
    public static function addInterval(DateTime $from, string $interval): DateTime {
        $next = Carbon::instance($from);

        return match ($interval) {
            self::INTERVAL_DAILY => $next->addDay(),
            self::INTERVAL_WEEKLY => $next->addWeek(),
            self::INTERVAL_BIWEEKLY => $next->addWeeks(2),
            self::INTERVAL_MONTHLY => $next->addMonthNoOverflow(),
            self::INTERVAL_QUARTERLY => $next->addQuarterNoOverflow(),
            self::INTERVAL_YEARLY => $next->addYear(),
            default => throw new InvalidArgumentException("Invalid subscription interval: {$interval}"),
        };
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
        $this->_amount = Payment::parseMoney($value);
    }

    /**
     * @return PaymentType|null
     */
    public function getPaymentType(): ?PaymentType {
        return $this->paymentTypeId !== null
            ? QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeById($this->paymentTypeId)
            : null;
    }

    /**
     * @inheritdoc
     */
    public static function get(string|int $id): ?static {
        return QrPayments::getInstance()->getSubscriptionTypes()->getSubscriptionTypeById((int) $id);
    }

    /**
     * @inheritdoc
     */
    public function getId(): string|int|null {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function getUiLabel(): string {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getCpEditUrl(): ?string {
        return $this->id !== null ? UrlHelper::cpUrl("qr-payments/subscription-types/{$this->id}") : null;
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        return array_merge(parent::defineRules(), [
            [['name', 'handle', 'paymentTypeId', 'amount', 'interval'], 'required'],

            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 64],
            [
                ['handle'], 'match',
                'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/',
                'message' => Craft::t('qr-payments', 'This can only contain letters, numbers, and underscores, and must start with a letter.'),
            ],
            [['handle'], 'validateHandleIsUnique'],

            [['paymentTypeId'], 'integer'],
            [['paymentTypeId'], 'validatePaymentTypeExists'],

            [['amount'], 'validateAmount'],
            [['interval'], 'in', 'range' => array_keys(self::intervals())],

            [['subscriptionRequestedTemplate', 'subscriptionCancelledTemplate', 'subscriptionExpiredTemplate', 'subscriptionActivatedTemplate', 'subscriptionExpiringTemplate'], 'string'],
            [['subscriptionRequestedTemplate', 'subscriptionCancelledTemplate', 'subscriptionExpiredTemplate', 'subscriptionActivatedTemplate', 'subscriptionExpiringTemplate'], 'validateTemplateExists'],
        ]);
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validateHandleIsUnique(string $attribute): void {
        $query = (new Query())
            ->from(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES)
            ->where(['handle' => $this->$attribute]);

        if ($this->id) $query->andWhere(['not', ['id' => $this->id]]);

        if ($query->exists()) {
            $this->addError($attribute, Craft::t('qr-payments', 'This handle is already in use.'));
        }
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validatePaymentTypeExists(string $attribute): void {
        $id = $this->$attribute;

        if ($id === null) return;

        if (QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeById($id) === null) {
            $this->addError($attribute, Craft::t('qr-payments', 'Could not find the selected payment type.'));
        }
    }

    /**
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
     * Catches a typo'd override template path at save time, rather than
     * only failing once a subscription email actually tries to render it
     * — same reasoning as `PaymentType::validateTemplateExists()`.
     *
     * @param string $attribute
     * @return void
     */
    public function validateTemplateExists(string $attribute): void {
        $template = $this->$attribute;

        if ($template === '') return;

        if (!Craft::$app->getView()->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            $this->addError($attribute, Craft::t('qr-payments', 'Could not find the template “{template}”.', [
                'template' => $template,
            ]));
        }
    }
}
