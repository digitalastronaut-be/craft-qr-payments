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
use digitalastronaut\craftqrpayments\helpers\Table;

/**
 * Class m261115_140000_remove_subscription_amount_interval_overrides
 *
 * Drops `qr_payments_subscriptions.amount`/`interval` — the per-subscriber
 * override `m261113_161500_rework_subscriptions_for_types` introduced
 * alongside `SubscriptionType`. Every subscriber on a plan is charged the
 * same amount, on the same cadence; `Subscription::getCoverageEnd()` and
 * `SubscriptionsService::createPaymentFor()` now read price/cadence
 * straight off the linked `SubscriptionType`, same as `getPaymentType()`
 * already did.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261115_140000_remove_subscription_amount_interval_overrides extends Migration {
    public function safeUp(): bool {
        if ($this->db->columnExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'amount')) {
            $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'amount');
        }

        if ($this->db->columnExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'interval')) {
            $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'interval');
        }

        return true;
    }

    public function safeDown(): bool {
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'amount', $this->decimal(12, 2)->null());
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'interval', $this->string(20)->null());

        return true;
    }
}
