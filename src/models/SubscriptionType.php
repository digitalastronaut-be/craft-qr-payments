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
use craft\base\Model;
use craft\db\Query;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\QrPayments;

use Money\Money;

/**
 * Class SubscriptionType
 *
 * The shared plan definition every `Subscription` enrolled in it
 * references, instead of carrying its own copy of the same data — the
 * same relationship `PaymentType` has to `Payment`. Holds the default
 * price/cadence and which `PaymentType` its generated payments use for
 * beneficiary/IBAN/custom fields/templates.
 *
 * See PAYMENT_TYPES_AND_SUBSCRIPTIONS_PLAN.md phase 2's pricing-inheritance
 * decision: a `Subscription`'s own `amount`/`interval` are an optional
 * per-subscriber override (`Subscription::getEffectiveAmount()`/
 * `getEffectiveInterval()`) that falls back to this type's values — so
 * changing `$amount`/`$interval` here immediately applies to every
 * non-overridden subscriber on their next cycle.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class SubscriptionType extends Model {
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
}
