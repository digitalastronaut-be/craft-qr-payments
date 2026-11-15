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

namespace digitalastronaut\craftqrpayments\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\MigrationHelper;
use craft\helpers\StringHelper;
use digitalastronaut\craftqrpayments\helpers\Table;

/**
 * Class m261113_161500_rework_subscriptions_for_types
 *
 * Moves `amount`/`interval`/`paymentTypeId` off `qr_payments_subscriptions`
 * and onto the new `qr_payments_subscriptiontypes` (from
 * `m261113_160000_add_subscription_types`): every existing subscription
 * row is pointed at a `SubscriptionType` auto-created for its exact
 * `(paymentTypeId, amount, interval)` combination, so no existing data is
 * silently dropped — same "adopt what already exists" approach
 * `m261113_090000_add_payment_types` used for the Default payment type's
 * field layout. `amount`/`interval` stay on the table afterward, but as
 * nullable per-subscriber overrides rather than required fields (see
 * PAYMENT_TYPES_AND_SUBSCRIPTIONS_PLAN.md phase 2's pricing-inheritance
 * decision: `Subscription::getEffectiveAmount()`/`getEffectiveInterval()`
 * fall back to the live `SubscriptionType` values when these are blank).
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class m261113_161500_rework_subscriptions_for_types extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', $this->integer()->null()->after('paymentTypeId'));

        // Relax these to nullable *before* the backfill below, which nulls
        // them out on every migrated row.
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'amount', $this->decimal(12, 2)->null());
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'interval', $this->string(20)->null());

        $this->backfillSubscriptionTypes();

        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', $this->integer()->notNull());
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'id', 'CASCADE', null);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', false);

        MigrationHelper::dropForeignKeyIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'paymentTypeId', $this);
        MigrationHelper::dropIndexIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'paymentTypeId', false, $this);
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'paymentTypeId');

        return true;
    }

    public function safeDown(): bool {
        MigrationHelper::dropForeignKeyIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', $this);
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId');

        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'paymentTypeId', $this->integer()->null()->after('email'));
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'amount', $this->decimal(12, 2)->notNull());
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'interval', $this->string(20)->notNull());

        return true;
    }

    /**
     * For every distinct `(paymentTypeId, amount, interval)` combination
     * actually present in `qr_payments_subscriptions`, creates one
     * `SubscriptionType` row ("Migrated plan 1", handle `migratedPlan1`,
     * …) and points every matching subscription row at it.
     *
     * @return void
     */
    private function backfillSubscriptionTypes(): void {
        $combinations = (new Query())
            ->select(['paymentTypeId', 'amount', 'interval'])
            ->from(Table::QR_PAYMENTS_SUBSCRIPTIONS)
            ->distinct()
            ->all();

        foreach ($combinations as $n => $combination) {
            $subscriptionTypeId = $this->insertSubscriptionType($n + 1, $combination);

            // Null out amount/interval on the migrated rows themselves —
            // they're the new type's values verbatim, not a deliberate
            // per-subscriber override an admin set, so these subscribers
            // should default to following the type's price/interval live
            // (see the class docblock), not look locked to a copy of it.
            $this->update(Table::QR_PAYMENTS_SUBSCRIPTIONS, [
                'subscriptionTypeId' => $subscriptionTypeId,
                'amount' => null,
                'interval' => null,
            ], [
                'paymentTypeId' => $combination['paymentTypeId'],
                'amount' => $combination['amount'],
                'interval' => $combination['interval'],
            ], updateTimestamp: false);
        }
    }

    /**
     * @param int $n 1-based index, used to number the generated name/handle.
     * @param array{paymentTypeId: int, amount: string, interval: string} $combination
     * @return int The new row's id.
     */
    private function insertSubscriptionType(int $n, array $combination): int {
        $this->insert(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, [
            'name' => "Migrated plan {$n}",
            'handle' => "migratedPlan{$n}",
            'paymentTypeId' => $combination['paymentTypeId'],
            'amount' => $combination['amount'],
            'interval' => $combination['interval'],
            'uid' => StringHelper::UUID(),
        ]);

        return (int) $this->db->getLastInsertID();
    }
}
