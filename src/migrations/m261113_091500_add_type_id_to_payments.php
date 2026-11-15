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
use digitalastronaut\craftqrpayments\helpers\Table;

/**
 * Class m261113_091500_add_type_id_to_payments
 *
 * Links every payment to a `PaymentType` row. Backfills existing payments
 * to the "Default" type seeded by `m261113_090000_add_payment_types`
 * before the column is made required, so installs with existing payments
 * don't end up with orphaned rows.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0-beta
 */
class m261113_091500_add_type_id_to_payments extends Migration {
    private const string TYPE_ID_FK = 'qr_payments_payments_typeid_fk';

    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTS, 'typeId', $this->integer()->null()->after('id'));

        $this->backfillDefaultType();

        $this->alterColumn(Table::QR_PAYMENTS_PAYMENTS, 'typeId', $this->integer()->notNull());

        $this->addForeignKey(self::TYPE_ID_FK, Table::QR_PAYMENTS_PAYMENTS, 'typeId', Table::QR_PAYMENTS_PAYMENTTYPES, 'id', 'CASCADE', null);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'typeId', false);

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKey(self::TYPE_ID_FK, Table::QR_PAYMENTS_PAYMENTS);
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTS, 'typeId');

        return true;
    }

    /**
     * @return void
     */
    private function backfillDefaultType(): void {
        $defaultTypeId = (new Query())
            ->select(['id'])
            ->from(Table::QR_PAYMENTS_PAYMENTTYPES)
            ->where(['handle' => 'default'])
            ->scalar();

        $this->update(Table::QR_PAYMENTS_PAYMENTS, ['typeId' => $defaultTypeId], ['typeId' => null], updateTimestamp: false);
    }
}
