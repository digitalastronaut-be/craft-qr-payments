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
 * Class m261113_130000_add_payment_reminder_email
 *
 * Frees up the "payment reminder" name for a new payer-facing email (sent
 * 7 days before a payment is due, and again the day it expires) by
 * renaming the existing admin-facing digest override column to
 * `paymentsSummaryTemplate` — it's the one rendered by the bundled
 * `_paymentsSummary.twig` (itself renamed from `_paymentReminder.twig`),
 * not the new `_paymentReminder.twig`. Then adds the new
 * `paymentReminderTemplate` column for the new email.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.4.0-beta
 */
class m261113_130000_add_payment_reminder_email extends Migration {
    public function safeUp(): bool {
        $this->renameColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentReminderTemplate', 'paymentsSummaryTemplate');
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentReminderTemplate', $this->string(255)->notNull()->defaultValue('')->after('paymentsSummaryTemplate'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentReminderTemplate');
        $this->renameColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentsSummaryTemplate', 'paymentReminderTemplate');

        return true;
    }
}
