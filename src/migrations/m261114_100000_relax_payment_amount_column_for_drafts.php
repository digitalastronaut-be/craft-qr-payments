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
 * Class m261114_100000_relax_payment_amount_column_for_drafts
 *
 * Same fix as `m261114_090000_relax_subscription_columns_for_drafts`, for
 * `Payment`: `hasDrafts()` is now `true` and the bespoke "New payment" CP
 * form is gone, replaced by the native unpublished-draft flow. A brand-new
 * draft's very first save runs under `Element::SCENARIO_ESSENTIALS`, which
 * skips `amount`'s `required` rule by design (see `Payment::defineRules()`) —
 * but the column was still `NOT NULL`, so that first save failed with a DB
 * integrity error before validation ever got a say. `email` was already
 * nullable; `beneficiaryName`/`iban`/`remittanceReference` stay `NOT NULL`
 * since `Payment::beforeValidate()` always derives real values for them
 * before any save, draft or not.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261114_100000_relax_payment_amount_column_for_drafts extends Migration {
    public function safeUp(): bool {
        $this->alterColumn(Table::QR_PAYMENTS_PAYMENTS, 'amount', $this->decimal(12, 2)->null());

        return true;
    }

    public function safeDown(): bool {
        $this->alterColumn(Table::QR_PAYMENTS_PAYMENTS, 'amount', $this->decimal(12, 2)->notNull());

        return true;
    }
}
