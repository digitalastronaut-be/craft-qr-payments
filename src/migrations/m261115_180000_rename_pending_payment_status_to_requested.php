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
 * Class m261115_180000_rename_pending_payment_status_to_requested
 *
 * Renames `Payment::STATUS_PENDING` (`pending`) to `STATUS_REQUESTED`
 * (`requested`) — matches the naming `Subscription::STATUS_REQUESTED`
 * already uses for the same "created, nothing happened yet" state, so
 * both elements describe it the same way. Only the stored `status` column
 * holds the literal string; `statusHistory` entries key off `type`
 * (`created`/`payment`/`payer_reported`/`refund`/`cancellation`), never
 * `status`, so there's nothing to migrate there.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261115_180000_rename_pending_payment_status_to_requested extends Migration {
    public function safeUp(): bool {
        $this->update(Table::QR_PAYMENTS_PAYMENTS, ['status' => 'requested'], ['status' => 'pending']);

        return true;
    }

    public function safeDown(): bool {
        $this->update(Table::QR_PAYMENTS_PAYMENTS, ['status' => 'pending'], ['status' => 'requested']);

        return true;
    }
}
