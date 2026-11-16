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
 * Builds the plugin's full current schema in one shot. A fresh install
 * only ever runs this migration: Craft marks every other migration in
 * this directory as already applied without actually running it, on the
 * assumption that this file already reflects everything they did. Keep
 * it in sync with the schema those migrations actually produce, rather
 * than relying on them to build it up on a new install.
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

    /**
     * @return void
     */
    public function createTables(): void {
        $this->createPaymentTypesTable();
        $this->createSubscriptionTypesTable();
        $this->createSubscriptionsTable();
        $this->createPaymentsTable();
        $this->createEmailLogTable();
    }

    /**
     * @return void
     */
    public function createForeignKeys(): void {
        $this->addForeignKey(null, Table::QR_PAYMENTS_PAYMENTTYPES, 'fieldLayoutId', Table::FIELDLAYOUTS, 'id', 'SET NULL', null);

        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'paymentTypeId', Table::QR_PAYMENTS_PAYMENTTYPES, 'id', 'CASCADE', null);

        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'id', Table::ELEMENTS, 'id', 'CASCADE', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'userId', Table::USERS, 'id', 'SET NULL', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'id', 'CASCADE', null);

        $this->addForeignKey(null, Table::QR_PAYMENTS_PAYMENTS, 'id', Table::ELEMENTS, 'id', 'CASCADE', null);
        $this->addForeignKey('qr_payments_payments_typeid_fk', Table::QR_PAYMENTS_PAYMENTS, 'typeId', Table::QR_PAYMENTS_PAYMENTTYPES, 'id', 'CASCADE', null);
        $this->addForeignKey('qr_payments_payments_subscriptionid_fk', Table::QR_PAYMENTS_PAYMENTS, 'subscriptionId', Table::QR_PAYMENTS_SUBSCRIPTIONS, 'id', 'SET NULL', null);
        $this->addForeignKey('qr_payments_payments_userid_fk', Table::QR_PAYMENTS_PAYMENTS, 'userId', Table::USERS, 'id', 'SET NULL', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_PAYMENTS, 'confirmedByUserId', Table::USERS, 'id', 'SET NULL', null);

        $this->addForeignKey(null, Table::QR_PAYMENTS_EMAIL_LOG, 'paymentId', Table::QR_PAYMENTS_PAYMENTS, 'id', 'CASCADE', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_EMAIL_LOG, 'subscriptionId', Table::QR_PAYMENTS_SUBSCRIPTIONS, 'id', 'CASCADE', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_EMAIL_LOG, 'triggeredByUserId', Table::USERS, 'id', 'SET NULL', null);
    }

    /**
     * @return void
     */
    public function createIndexes(): void {
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTTYPES, 'handle', true);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTTYPES, 'fieldLayoutId', false);

        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'handle', true);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'paymentTypeId', false);

        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'userId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'status', false);

        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'remittanceReference', true);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'status', false);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'iban', false);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'typeId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'subscriptionId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'userId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_PAYMENTS, 'confirmedByUserId', false);

        $this->createIndex(null, Table::QR_PAYMENTS_EMAIL_LOG, 'paymentId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_EMAIL_LOG, 'subscriptionId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_EMAIL_LOG, 'historyEntryId', false);
    }

    /**
     * @return void
     */
    public function dropTables(): void {
        $this->dropTableIfExists(Table::QR_PAYMENTS_EMAIL_LOG);
        $this->dropTableIfExists(Table::QR_PAYMENTS_PAYMENTS);
        $this->dropTableIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONS);
        $this->dropTableIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES);
        $this->dropTableIfExists(Table::QR_PAYMENTS_PAYMENTTYPES);
    }

    /**
     * @return void
     */
    public function dropForeignKeys(): void {
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_EMAIL_LOG);
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_PAYMENTS);
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_SUBSCRIPTIONS);
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES);
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_PAYMENTTYPES);
    }

    /**
     * `PaymentType` carries its own beneficiary/IBAN/BIC override, template
     * overrides and reminder settings, so a `Payment` can resolve all of
     * it through its `typeId` without falling back to plugin-wide settings.
     *
     * @return void
     */
    private function createPaymentTypesTable(): void {
        $this->createTable(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'id' => $this->primaryKey(),

            'name' => $this->string(255)->notNull(),
            'beneficiaryName' => $this->string(70)->notNull()->defaultValue(''),
            'iban' => $this->string(34)->notNull()->defaultValue(''),
            'bic' => $this->string(11)->notNull()->defaultValue(''),
            'referencePrefix' => $this->string(26)->notNull()->defaultValue(''),
            'handle' => $this->string(64)->notNull(),
            'fieldLayoutId' => $this->integer()->null(),
            'defaultDueDateDays' => $this->integer()->notNull()->defaultValue(30),
            'payTemplate' => $this->string(255)->notNull()->defaultValue(''),
            'paymentCreatedTemplate' => $this->string(255)->notNull()->defaultValue(''),
            'paymentStatusChangedTemplate' => $this->string(255)->notNull()->defaultValue(''),
            'reminderRecipients' => $this->json()->null(),
            'paymentsSummaryTemplate' => $this->string(255)->notNull()->defaultValue(''),
            'paymentReminderTemplate' => $this->string(255)->notNull()->defaultValue(''),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * The shared plan definition (price, interval, which `PaymentType` its
     * generated payments use) that `qr_payments_subscriptions` rows
     * reference, rather than carrying their own copy of that data.
     *
     * @return void
     */
    private function createSubscriptionTypesTable(): void {
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
     * `Subscription` is a Craft element (like `Payment`), so `id` is also a
     * FK to `elements.id`. `email`/`subscriptionTypeId` are nullable
     * because a brand-new unpublished draft saves under
     * `Element::SCENARIO_ESSENTIALS`, which skips their `required` rule by
     * design. Price/cadence live entirely on the linked `SubscriptionType`
     * — there's no per-subscriber override, so no `amount`/`interval`
     * columns here. No `startedAt` either — it was never read by anything
     * (`getCoverageEnd()` derives entirely from payment history), just a
     * historical snapshot nothing needed.
     *
     * @return void
     */
    private function createSubscriptionsTable(): void {
        $this->createTable(Table::QR_PAYMENTS_SUBSCRIPTIONS, [
            'id' => $this->primaryKey(),

            'userId' => $this->integer()->null(),
            'email' => $this->string(255)->null(),
            'subscriptionTypeId' => $this->integer()->null(),

            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'statusHistory' => $this->json()->null(),

            'endedAt' => $this->dateTime()->null(),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * `amount` is nullable for the same `SCENARIO_ESSENTIALS` draft reason
     * as `Subscription`'s columns above; `beneficiaryName`/`iban`/
     * `remittanceReference` stay required since `Payment::beforeValidate()`
     * always derives real values for them before any save, draft or not.
     *
     * @return void
     */
    private function createPaymentsTable(): void {
        $this->createTable(Table::QR_PAYMENTS_PAYMENTS, [
            'id' => $this->primaryKey(),

            'typeId' => $this->integer()->notNull(),
            'subscriptionId' => $this->integer()->null(),
            'userId' => $this->integer()->null(),
            'email' => $this->string(255)->null(),
            'beneficiaryName' => $this->string(70)->notNull(),
            'iban' => $this->string(34)->notNull(),
            'bic' => $this->string(11)->null(),
            'amount' => $this->decimal(12, 2)->null(),

            'remittanceReference' => $this->string(35)->notNull(),
            'remittanceText' => $this->string(140)->null(),
            'qrCode' => $this->binary()->null(),

            'status' => $this->string(20)->notNull()->defaultValue('requested'),
            'statusHistory' => $this->json()->null(),

            'dueDate' => $this->dateTime()->null(),
            'confirmedAt' => $this->dateTime()->null(),
            'confirmedByUserId' => $this->integer()->null(),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * One generic log of every email the plugin ever attempts to send —
     * see `m261116_090000_add_email_log_table` for the full rationale.
     *
     * @return void
     */
    private function createEmailLogTable(): void {
        $this->createTable(Table::QR_PAYMENTS_EMAIL_LOG, [
            'id' => $this->primaryKey(),

            'paymentId' => $this->integer()->null(),
            'subscriptionId' => $this->integer()->null(),
            'historyEntryId' => $this->string(36)->null(),

            'jobClass' => $this->string(255)->notNull(),
            'jobParams' => $this->json()->notNull(),

            'recipient' => $this->string(255)->notNull(),
            'subject' => $this->string(255)->notNull(),
            'status' => $this->string(20)->notNull(),
            'error' => $this->text()->null(),

            'triggeredByUserId' => $this->integer()->null(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }
}
