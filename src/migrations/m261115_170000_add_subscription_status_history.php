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
 * Class m261115_170000_add_subscription_status_history
 *
 * Adds `qr_payments_subscriptions.statusHistory` — same shape and purpose
 * as `Payment::$statusHistory`: an append-only JSON log of this
 * subscription's own status transitions (`requested`/`active`/
 * `cancelled`), pushed to by `Subscription::beforeSave()` (on creation)
 * and `SubscriptionsService::activate()`/`cancel()`. `Subscription::
 * getStatusTimeline()` merges this real log with synthesized `expired`
 * spans derived from payment history — see that method's docblock.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261115_170000_add_subscription_status_history extends Migration {
    public function safeUp(): bool {
        if (!$this->db->columnExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'statusHistory')) {
            $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'statusHistory', $this->json()->null());
        }

        return true;
    }

    public function safeDown(): bool {
        if ($this->db->columnExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'statusHistory')) {
            $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'statusHistory');
        }

        return true;
    }
}
