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
 * Class m261113_160000_add_subscription_types
 *
 * Introduces `qr_payments_subscriptiontypes`: the shared plan definition
 * (price, interval, which `PaymentType` its generated payments use) that
 * `qr_payments_subscriptions` rows will reference instead of carrying
 * their own copy of that data — same split `m261113_090000_add_payment_types`
 * made for `Payment`/`PaymentType`. No seeded row: unlike `PaymentType`,
 * nothing implicitly depends on a default one existing yet.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class m261113_160000_add_subscription_types extends Migration {
    public function safeUp(): bool {
        $this->createTables();
        $this->createForeignKeys();
        $this->createIndexes();

        return true;
    }

    public function safeDown(): bool {
        $this->dropForeignKeys();
        $this->dropTableIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES);

        return true;
    }

    /**
     * @return void
     */
    public function createTables(): void {
        $this->createTable(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, [
            'id' => $this->primaryKey(),

            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(64)->notNull(),
            'paymentTypeId' => $this->integer()->notNull(),

            'amount' => $this->decimal(12, 2)->notNull(),
            'interval' => $this->string(20)->notNull(),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * @return void
     */
    public function createForeignKeys(): void {
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'paymentTypeId', Table::QR_PAYMENTS_PAYMENTTYPES, 'id', 'CASCADE', null);
    }

    /**
     * @return void
     */
    public function createIndexes(): void {
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'handle', true);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'paymentTypeId', false);
    }

    /**
     * @return void
     */
    public function dropForeignKeys(): void {
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES);
    }
}
