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
use digitalastronaut\craftqrpayments\QrPayments;

/**
 * Class m261113_110000_add_remaining_settings_fields_to_payment_types
 *
 * Moves `referencePrefix`, `payTemplate`, `paymentCreatedTemplate` and
 * `paymentStatusChangedTemplate` from the plugin's global (project-config-
 * backed, so uneditable on a live site with `allowAdminChanges` off)
 * `Settings` onto `PaymentType` rows, and tightens `beneficiaryName`/`iban`/
 * `defaultDueDateDays` from optional overrides into required per-type
 * values now that there's no more global fallback for them.
 *
 * Backfills every existing PaymentType from the current global `Settings`
 * BEFORE those properties are removed from the `Settings` model — this
 * must keep working against the pre-removal `Settings` class, so this
 * migration has to run (and this backfill complete) before any of that
 * model/controller/template code is touched.
 *
 * `reminderRecipients`/`paymentReminderTemplate` are deliberately left
 * alone here — moving those needs the reminder digest itself restructured
 * (see SETTINGS_CLEANUP_PLAN.md item 6b), not just a column move.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.2.0-beta
 */
class m261113_110000_add_remaining_settings_fields_to_payment_types extends Migration {
    public function safeUp(): bool {
        $this->addColumns();
        $this->backfillFromGlobalSettings();
        $this->tightenDefaultDueDateDays();

        return true;
    }

    public function safeDown(): bool {
        $this->alterColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'defaultDueDateDays', $this->integer()->null());

        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentStatusChangedTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentCreatedTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'payTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'referencePrefix');

        return true;
    }

    /**
     * @return void
     */
    public function addColumns(): void {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'referencePrefix', $this->string(26)->notNull()->defaultValue('')->after('bic'));
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'payTemplate', $this->string(255)->notNull()->defaultValue('')->after('defaultDueDateDays'));
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentCreatedTemplate', $this->string(255)->notNull()->defaultValue('')->after('payTemplate'));
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'paymentStatusChangedTemplate', $this->string(255)->notNull()->defaultValue('')->after('paymentCreatedTemplate'));
    }

    /**
     * @return void
     */
    public function backfillFromGlobalSettings(): void {
        $settings = QrPayments::getInstance()->getSettings();

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'beneficiaryName' => $settings->beneficiaryName,
        ], ['beneficiaryName' => '']);

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'iban' => $settings->iban,
        ], ['iban' => '']);

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'bic' => $settings->bic,
        ], ['bic' => '']);

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'defaultDueDateDays' => $settings->defaultDueDateDays,
        ], ['defaultDueDateDays' => null]);

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'referencePrefix' => $settings->referencePrefix,
        ], ['referencePrefix' => '']);

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'payTemplate' => $settings->payTemplate,
        ], ['payTemplate' => '']);

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'paymentCreatedTemplate' => $settings->paymentCreatedTemplate,
        ], ['paymentCreatedTemplate' => '']);

        $this->update(Table::QR_PAYMENTS_PAYMENTTYPES, [
            'paymentStatusChangedTemplate' => $settings->paymentStatusChangedTemplate,
        ], ['paymentStatusChangedTemplate' => '']);
    }

    /**
     * @return void
     */
    public function tightenDefaultDueDateDays(): void {
        $this->alterColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'defaultDueDateDays', $this->integer()->notNull()->defaultValue(30));
    }
}
