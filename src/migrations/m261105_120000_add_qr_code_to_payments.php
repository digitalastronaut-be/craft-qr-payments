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
 * Class m261105_120000_add_qr_code_to_payments
 *
 * `Install::createTables()` dropped the `qrCode` column when the plugin's
 * migrations were consolidated, even though `Payment` and `PaymentQuery`
 * both reference it unconditionally — breaking every fresh install with
 * `SQLSTATE[42S22]: Column not found: 1054 Unknown column
 * 'qr_payments_payments.qrCode'` as soon as the Payment element index
 * loads. Sites installed before that regression already have the column,
 * so this only touches installs that are missing it.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class m261105_120000_add_qr_code_to_payments extends Migration {
    public function safeUp(): bool {
        if (!$this->db->columnExists(Table::QR_PAYMENTS_PAYMENTS, 'qrCode')) {
            $this->addColumn(Table::QR_PAYMENTS_PAYMENTS, 'qrCode', $this->binary()->after('remittanceText'));
        }

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTS, 'qrCode');

        return true;
    }
}
