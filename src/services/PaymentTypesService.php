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
use craft\helpers\Json;
use craft\helpers\StringHelper;

use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\models\PaymentType;

use yii\base\Exception;

/**
 * Class PaymentTypesService
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0-beta
 */
class PaymentTypesService extends Component {
    private ?array $_paymentTypes = null;

    /**
     * @return PaymentType[]
     */
    public function getAllPaymentTypes(): array {
        if ($this->_paymentTypes !== null) return $this->_paymentTypes;

        $rows = $this->createPaymentTypeQuery()->all();

        return $this->_paymentTypes = array_map(function(array $row) {
            // a plain Query (unlike ActiveRecord) doesn't auto-decode a json
            // column — it comes back as the raw JSON string, same as
            // Payment::$statusHistory handles via its own setter.
            $row['reminderRecipients'] = $row['reminderRecipients'] !== null
                ? (Json::decode($row['reminderRecipients']) ?: [])
                : [];

            return new PaymentType($row);
        }, $rows);
    }

    /**
     * @param int $id
     * @return PaymentType|null
     */
    public function getPaymentTypeById(int $id): ?PaymentType {
        foreach ($this->getAllPaymentTypes() as $paymentType) {
            if ($paymentType->id === $id) return $paymentType;
        }

        return null;
    }

    /**
     * @param string $handle
     * @return PaymentType|null
     */
    public function getPaymentTypeByHandle(string $handle): ?PaymentType {
        foreach ($this->getAllPaymentTypes() as $paymentType) {
            if ($paymentType->handle === $handle) return $paymentType;
        }

        return null;
    }

    /**
     * The payment type used implicitly wherever callers don't pick one yet
     * (see PAYMENT_TYPES_AND_SUBSCRIPTIONS_PLAN.md phase 1, step 9). Always
     * the lowest-id type — i.e. whichever existed first — rather than one
     * looked up by a hardcoded handle: the seeded type's `handle` is just
     * its initial value, editable like any other attribute once the
     * Payment Types CP screen exists, so relying on it staying `"default"`
     * would silently break the moment someone renames it.
     *
     * @return PaymentType|null Null only if no payment type exists at all.
     */
    public function getDefaultPaymentType(): ?PaymentType {
        $types = $this->getAllPaymentTypes();

        if (empty($types)) return null;

        usort($types, static fn(PaymentType $a, PaymentType $b) => $a->id <=> $b->id);

        return $types[0];
    }

    /**
     * @param PaymentType $paymentType
     * @param bool $runValidation
     * @return bool False if validation failed.
     * @throws Exception if the field layout itself fails to save
     */
    public function savePaymentType(PaymentType $paymentType, bool $runValidation = true): bool {
        if ($runValidation && !$paymentType->validate()) return false;

        $fieldLayout = $paymentType->getFieldLayout();

        if (!Craft::$app->getFields()->saveLayout($fieldLayout)) {
            throw new Exception('Could not save the payment type’s field layout.');
        }

        $paymentType->fieldLayoutId = $fieldLayout->id;

        $attributes = [
            'name' => $paymentType->name,
            'handle' => $paymentType->handle,
            'fieldLayoutId' => $paymentType->fieldLayoutId,
            'defaultDueDateDays' => $paymentType->defaultDueDateDays,
            'beneficiaryName' => $paymentType->beneficiaryName,
            'iban' => $paymentType->iban,
            'bic' => $paymentType->bic,
            'referencePrefix' => $paymentType->referencePrefix,
            'payTemplate' => $paymentType->payTemplate,
            'paymentCreatedTemplate' => $paymentType->paymentCreatedTemplate,
            'paymentStatusChangedTemplate' => $paymentType->paymentStatusChangedTemplate,
            'reminderRecipients' => $paymentType->reminderRecipients,
            'paymentsSummaryTemplate' => $paymentType->paymentsSummaryTemplate,
            'paymentReminderTemplate' => $paymentType->paymentReminderTemplate,
        ];

        $db = Craft::$app->getDb();

        if ($paymentType->id === null) {
            $paymentType->uid = StringHelper::UUID();

            $db->createCommand()
                ->insert(Table::QR_PAYMENTS_PAYMENTTYPES, $attributes + ['uid' => $paymentType->uid])
                ->execute();

            $paymentType->id = (int) $db->getLastInsertID();
        } else {
            $db->createCommand()
                ->update(Table::QR_PAYMENTS_PAYMENTTYPES, $attributes, ['id' => $paymentType->id])
                ->execute();
        }

        $this->_paymentTypes = null;

        return true;
    }

    /**
     * @param PaymentType $paymentType
     * @return bool False if this is the only remaining type, or if any payment or subscription type still references it.
     */
    public function deletePaymentType(PaymentType $paymentType): bool {
        if (count($this->getAllPaymentTypes()) <= 1) return false;

        $inUse = (new Query())
            ->from(Table::QR_PAYMENTS_PAYMENTS)
            ->where(['typeId' => $paymentType->id])
            ->exists();

        // A SubscriptionType's own generated payments rely on this type's
        // beneficiary/IBAN/custom fields/templates — deleting it out from
        // under one would silently orphan it.
        $inUse = $inUse || (new Query())
            ->from(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES)
            ->where(['paymentTypeId' => $paymentType->id])
            ->exists();

        if ($inUse) return false;

        Craft::$app->getDb()->createCommand()
            ->delete(Table::QR_PAYMENTS_PAYMENTTYPES, ['id' => $paymentType->id])
            ->execute();

        if ($paymentType->fieldLayoutId !== null) {
            Craft::$app->getFields()->deleteLayoutById($paymentType->fieldLayoutId);
        }

        $this->_paymentTypes = null;

        return true;
    }

    /**
     * @return Query
     */
    private function createPaymentTypeQuery(): Query {
        return (new Query())
            ->select(['id', 'uid', 'name', 'handle', 'fieldLayoutId', 'defaultDueDateDays', 'beneficiaryName', 'iban', 'bic', 'referencePrefix', 'payTemplate', 'paymentCreatedTemplate', 'paymentStatusChangedTemplate', 'reminderRecipients', 'paymentsSummaryTemplate', 'paymentReminderTemplate'])
            ->from(Table::QR_PAYMENTS_PAYMENTTYPES)
            ->orderBy(['name' => SORT_ASC]);
    }
}
