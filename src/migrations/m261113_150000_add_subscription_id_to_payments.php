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
 * Class m261113_150000_add_subscription_id_to_payments
 *
 * Links a payment back to the `Subscription` that generated it. Nullable
 * and `SET NULL` on delete: a one-off payment never has one, and a payment
 * already stamped out by a since-deleted subscription stays on record
 * rather than being dragged down with it.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class m261113_150000_add_subscription_id_to_payments extends Migration {
    private const string SUBSCRIPTION_ID_FK = 'qr_payments_payments_subscriptionid_fk';

    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTS, 'subscriptionId', $this->integer()->null()->after('typeId'));

        $this->addForeignKey(self::SUBSCRIPTION_ID_FK, Table::QR_PAYMENTS_PAYMENTS, 'subscriptionId', Table::QR_PAYMENTS_SUBSCRIPTIONS, 'id', 'SET NULL', null);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'subscriptionId', false);

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKey(self::SUBSCRIPTION_ID_FK, Table::QR_PAYMENTS_PAYMENTS);
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTS, 'subscriptionId');

        return true;
    }
}
