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
 * Class Install
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class Install extends Migration {
    public function safeUp(): bool {
        $this->createTables();
        $this->createForeignKeys();
        $this->createIndexes();

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKeys();
        $this->dropTables();

        return true;
    }

    public function createTables(): void {
        $this->createTable(Table::QR_PAYMENTS_PAYMENTS, [
            'id' => $this->primaryKey(),

            'email' => $this->string(255)->null(),
            'beneficiaryName' => $this->string(70)->notNull(),
            'iban' => $this->string(34)->notNull(),
            'bic' => $this->string(11)->null(),
            'amount' => $this->decimal(12, 2)->notNull(),

            'remittanceReference' => $this->string(35)->notNull(),
            'remittanceText' => $this->string(140)->null(),

            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'statusHistory' => $this->json()->null(),

            'dueDate' => $this->dateTime()->null(),
            'confirmedAt' => $this->dateTime()->null(),
            'confirmedByUserId' => $this->integer()->null(),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * @return void
     */
    public function createForeignKeys(): void {
        $this->addForeignKey(null, Table::QR_PAYMENTS_PAYMENTS, 'id', Table::ELEMENTS, 'id', 'CASCADE', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_PAYMENTS, 'confirmedByUserId', Table::USERS, 'id', 'SET NULL', null);
    }

    /**
     * @return void
     */
    public function createIndexes(): void {
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'remittanceReference', true);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'status', false);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'iban', false);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'confirmedByUserId', false);
    }

    /**
     * @return void
     */
    public function dropTables(): void {
        $this->dropTableIfExists(Table::QR_PAYMENTS_PAYMENTS);
    }

    /**
     * @return void
     */
    public function dropForeignKeys(): void {
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_PAYMENTS);
    }
}
