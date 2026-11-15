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
use craft\helpers\Json;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\QrPayments;

/**
 * Class m261113_120000_add_reminder_fields_to_payment_types
 *
 * Moves `reminderRecipients`/`paymentReminderTemplate` from the plugin's
 * global `Settings` onto `PaymentType` rows — the last piece of the
 * SETTINGS_CLEANUP_PLAN.md item 6b work, deferred until the reminder
 * digest itself could be restructured to be per-type (`ReminderController`/
 * `SendPaymentReminderEmailJob`).
 *
 * Backfills every existing PaymentType from the current global `Settings`
 * BEFORE those properties are removed from the `Settings` model, same
 * discipline as `m261113_110000_...`.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.3.0-beta
 */
class m261113_120000_add_reminder_fields_to_payment_types extends Migration {
    public function safeUp(): bool {
        $this->addColumns();
        $this->backfillFromGlobalSettings();

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentReminderTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'reminderRecipients');

        return true;
    }

    /**
     * @return void
     */
    public function addColumns(): void {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'reminderRecipients', $this->json()->null()->after('paymentStatusChangedTemplate'));
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentReminderTemplate', $this->string(255)->notNull()->defaultValue('')->after('reminderRecipients'));
    }

    /**
     * @return void
     */
    public function backfillFromGlobalSettings(): void {
        $settings = QrPayments::getInstance()->getSettings();

        if ($settings->reminderRecipients) {
            $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
                'reminderRecipients' => Json::encode($settings->reminderRecipients),
            ], ['reminderRecipients' => null]);
        }

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'paymentReminderTemplate' => $settings->paymentReminderTemplate,
        ], ['paymentReminderTemplate' => '']);
    }
}
