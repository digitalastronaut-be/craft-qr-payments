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
use craft\helpers\StringHelper;
use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\helpers\Table;

/**
 * Class m261113_090000_add_payment_types
 *
 * Introduces `qr_payments_paymenttypes`, seeded with a single "Default"
 * type so existing installs keep working unchanged. If a field layout was
 * already assigned to the `Payment` element type, it's adopted as the
 * Default type's layout rather than left behind.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0-beta
 */
class m261113_090000_add_payment_types extends Migration {
    public function safeUp(): bool {
        $this->createTables();
        $this->createForeignKeys();
        $this->createIndexes();
        $this->seedDefaultPaymentType();

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKeys();
        $this->dropTableIfExists(Table::QR_PAYMENTS_PAYMENTTYPES);

        return true;
    }

    /**
     * @return void
     */
    public function createTables(): void {
        $this->createTable(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'id' => $this->primaryKey(),

            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(64)->notNull(),
            'fieldLayoutId' => $this->integer()->null(),
            'defaultDueDateDays' => $this->integer()->null(),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * @return void
     */
    public function createForeignKeys(): void {
        $this->addForeignKey(null, Table::QR_PAYMENTS_PAYMENTTYPES, 'fieldLayoutId', Table::FIELDLAYOUTS, 'id', 'SET NULL', null);
    }

    /**
     * @return void
     */
    public function createIndexes(): void {
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTTYPES, 'handle', true);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTTYPES, 'fieldLayoutId', false);
    }

    /**
     * @return void
     */
    public function dropForeignKeys(): void {
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_PAYMENTTYPES);
    }

    /**
     * @return void
     */
    private function seedDefaultPaymentType(): void {
        $existingLayoutId = (new Query())
            ->select(['id'])
            ->from(Table::FIELDLAYOUTS)
            ->where(['type' => Payment::class, 'dateDeleted' => null])
            ->scalar();

        $this->insert(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'name' => 'Default',
            'handle' => 'default',
            'fieldLayoutId' => $existingLayoutId ?: null,
            'uid' => StringHelper::UUID(),
        ]);
    }
}
