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
 * Class m261115_150000_remove_subscription_started_at
 *
 * Drops `qr_payments_subscriptions.startedAt`. Nothing ever read it:
 * `Subscription::getCoverageEnd()` (what actually decides when a
 * subscription's next payment is due) derives entirely from the latest
 * *paid* linked `Payment`'s own `dueDate` plus one interval — it never
 * looked at `startedAt`, same for `SubscriptionsService::getDueSubscriptions()`/
 * `advance()`. It was just a "when did this subscriber begin" snapshot
 * shown in the CP (a table column, a sort option, a metadata row), set
 * once at creation and otherwise unused — the same kind of unneeded state
 * `m261115_130000_remove_subscription_next_due_date` removed two
 * migrations before this one, just without that one's drift risk since
 * nothing ever updated this column after the initial save.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261115_150000_remove_subscription_started_at extends Migration {
    public function safeUp(): bool {
        if ($this->db->columnExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'startedAt')) {
            $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'startedAt');
        }

        return true;
    }

    public function safeDown(): bool {
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'startedAt', $this->dateTime()->null());

        return true;
    }
}
