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
use craft\db\Query;
use craft\helpers\MoneyHelper;
use craft\helpers\StringHelper;

use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\models\SubscriptionType;

/**
 * Class SubscriptionTypesService
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class SubscriptionTypesService extends Component {
    private ?array $_subscriptionTypes = null;

    /**
     * @return SubscriptionType[]
     */
    public function getAllSubscriptionTypes(): array {
        if ($this->_subscriptionTypes !== null) return $this->_subscriptionTypes;

        $rows = $this->createSubscriptionTypeQuery()->all();

        return $this->_subscriptionTypes = array_map(static fn(array $row) => new SubscriptionType($row), $rows);
    }

    /**
     * @param int $id
     * @return SubscriptionType|null
     */
    public function getSubscriptionTypeById(int $id): ?SubscriptionType {
        foreach ($this->getAllSubscriptionTypes() as $subscriptionType) {
            if ($subscriptionType->id === $id) return $subscriptionType;
        }

        return null;
    }

    /**
     * @param string $handle
     * @return SubscriptionType|null
     */
    public function getSubscriptionTypeByHandle(string $handle): ?SubscriptionType {
        foreach ($this->getAllSubscriptionTypes() as $subscriptionType) {
            if ($subscriptionType->handle === $handle) return $subscriptionType;
        }

        return null;
    }

    /**
     * @param SubscriptionType $subscriptionType
     * @param bool $runValidation
     * @return bool False if validation failed.
     */
    public function saveSubscriptionType(SubscriptionType $subscriptionType, bool $runValidation = true): bool {
        if ($runValidation && !$subscriptionType->validate()) return false;

        $attributes = [
            'name' => $subscriptionType->name,
            'handle' => $subscriptionType->handle,
            'paymentTypeId' => $subscriptionType->paymentTypeId,
            'amount' => MoneyHelper::toDecimal($subscriptionType->getAmount()) ?: null,
            'interval' => $subscriptionType->interval,
        ];

        $db = Craft::$app->getDb();

        if ($subscriptionType->id === null) {
            $subscriptionType->uid = StringHelper::UUID();

            $db->createCommand()
                ->insert(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, $attributes + ['uid' => $subscriptionType->uid])
                ->execute();

            $subscriptionType->id = (int) $db->getLastInsertID();
        } else {
            $db->createCommand()
                ->update(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, $attributes, ['id' => $subscriptionType->id])
                ->execute();
        }

        $this->_subscriptionTypes = null;

        return true;
    }

    /**
     * Unlike `PaymentTypesService::deletePaymentType()`, there's no "last
     * remaining type" guard here — nothing implicitly depends on a
     * `SubscriptionType` existing the way `Payment::init()` depends on a
     * default `PaymentType`, so zero of them is a valid state.
     *
     * @param SubscriptionType $subscriptionType
     * @return bool False if any subscription still references it.
     */
    public function deleteSubscriptionType(SubscriptionType $subscriptionType): bool {
        $inUse = (new Query())
            ->from(Table::QR_PAYMENTS_SUBSCRIPTIONS)
            ->where(['subscriptionTypeId' => $subscriptionType->id])
            ->exists();

        if ($inUse) return false;

        Craft::$app->getDb()->createCommand()
            ->delete(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, ['id' => $subscriptionType->id])
            ->execute();

        $this->_subscriptionTypes = null;

        return true;
    }

    /**
     * @return Query
     */
    private function createSubscriptionTypeQuery(): Query {
        return (new Query())
            ->select(['id', 'uid', 'name', 'handle', 'paymentTypeId', 'amount', 'interval'])
            ->from(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES)
            ->orderBy(['name' => SORT_ASC]);
    }
}
