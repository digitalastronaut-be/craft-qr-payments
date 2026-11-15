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
 * Class m261114_110000_add_user_id_to_payments
 *
 * Links a payment to a Craft user account, same owner/email split
 * `Subscription` already has: nullable and `SET NULL` on delete — a guest
 * payer never has one, and a payment already linked to a since-deleted
 * account stays on record rather than being dragged down with it. `email`
 * stays required regardless, so nothing about the existing identity model
 * changes for payers without an account.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261114_110000_add_user_id_to_payments extends Migration {
    private const string USER_ID_FK = 'qr_payments_payments_userid_fk';

    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTS, 'userId', $this->integer()->null()->after('subscriptionId'));

        $this->addForeignKey(self::USER_ID_FK, Table::QR_PAYMENTS_PAYMENTS, 'userId', Table::USERS, 'id', 'SET NULL', null);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'userId', false);

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKey(self::USER_ID_FK, Table::QR_PAYMENTS_PAYMENTS);
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTS, 'userId');

        return true;
    }
}
