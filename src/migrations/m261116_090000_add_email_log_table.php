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
 * Class m261116_090000_add_email_log_table
 *
 * One generic log of every email this plugin ever attempts to send,
 * written automatically by `SendTemplatedEmailJob::execute()` — covers
 * the 4 existing `Payment` emails and the 5 new `Subscription` ones alike,
 * so nothing has to log itself. `jobClass`/`jobParams` store exactly what
 * each job's own `__sleep()` already says is needed to reconstruct it,
 * which is what lets a logged row resend itself later. `historyEntryId`
 * links a row back to the `statusHistory` entry (on `Payment` or
 * `Subscription`) that caused it, when there is one — a reminder or
 * summary digest has none, and is only ever found through the plugin's
 * central email log page instead of inline on a status-history table.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
class m261116_090000_add_email_log_table extends Migration {
    public function safeUp(): bool {
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

        $this->addForeignKey(null, Table::QR_PAYMENTS_EMAIL_LOG, 'paymentId', Table::QR_PAYMENTS_PAYMENTS, 'id', 'CASCADE', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_EMAIL_LOG, 'subscriptionId', Table::QR_PAYMENTS_SUBSCRIPTIONS, 'id', 'CASCADE', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_EMAIL_LOG, 'triggeredByUserId', Table::USERS, 'id', 'SET NULL', null);

        $this->createIndex(null, Table::QR_PAYMENTS_EMAIL_LOG, 'paymentId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_EMAIL_LOG, 'subscriptionId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_EMAIL_LOG, 'historyEntryId', false);

        return true;
    }

    public function safeDown(): bool {
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_EMAIL_LOG);
        $this->dropTableIfExists(Table::QR_PAYMENTS_EMAIL_LOG);

        return true;
    }
}
