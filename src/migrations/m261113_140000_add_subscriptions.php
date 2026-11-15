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
use craft\db\Table as CraftTable;
use digitalastronaut\craftqrpayments\helpers\Table;

/**
 * Class m261113_140000_add_subscriptions
 *
 * Introduces `qr_payments_subscriptions`, a recurrence schedule that a
 * queue/cron job advances to stamp out the next linked `Payment`. A
 * subscription's owner is a `userId` (nullable FK to `craft\elements\User`,
 * `SET NULL` on delete) and/or an `email` snapshot taken at signup, the
 * same guest/account duality Craft Commerce uses for orders: sites with no
 * front-end accounts can run on `email` alone, while sites that need to
 * gate access on an active subscription have a stable `userId` to key off.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class m261113_140000_add_subscriptions extends Migration {
    public function safeUp(): bool {
        $this->createTables();
        $this->createForeignKeys();
        $this->createIndexes();

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKeys();
        $this->dropTableIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONS);

        return true;
    }

    /**
     * @return void
     */
    public function createTables(): void {
        $this->createTable(Table::QR_PAYMENTS_SUBSCRIPTIONS, [
            'id' => $this->primaryKey(),

            'userId' => $this->integer()->null(),
            'email' => $this->string(255)->notNull(),
            'paymentTypeId' => $this->integer()->notNull(),

            'amount' => $this->decimal(12, 2)->notNull(),
            'interval' => $this->string(20)->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),

            'nextDueDate' => $this->dateTime()->null(),
            'startedAt' => $this->dateTime()->notNull(),
            'endedAt' => $this->dateTime()->null(),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * @return void
     */
    public function createForeignKeys(): void {
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'userId', CraftTable::USERS, 'id', 'SET NULL', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'paymentTypeId', Table::QR_PAYMENTS_PAYMENTTYPES, 'id', 'CASCADE', null);
    }

    /**
     * @return void
     */
    public function createIndexes(): void {
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'userId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'paymentTypeId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'status', false);
    }

    /**
     * @return void
     */
    public function dropForeignKeys(): void {
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_SUBSCRIPTIONS);
    }
}
