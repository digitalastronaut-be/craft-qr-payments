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
 * Class m261115_130000_remove_subscription_next_due_date
 *
 * Drops `qr_payments_subscriptions.nextDueDate`. It was a separately
 * maintained schedule cache — `SubscriptionsService::advance()` pushed it
 * forward by one interval every time it stamped a cycle's `Payment`, and
 * `activate()` had to remember to seed it on a subscription's first
 * activation (a step that was missing until the fix two migrations
 * before this one, which is exactly the kind of drift this removal is
 * meant to rule out for good).
 *
 * `Subscription::getCoverageEnd()` replaces it entirely: the latest
 * *paid* `Payment`'s own `dueDate`, plus one effective interval,
 * computed on read rather than written and kept in sync by hand. It
 * can't drift, and it keeps working even if a cron run that would have
 * advanced a stored value gets missed.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261115_130000_remove_subscription_next_due_date extends Migration {
    public function safeUp(): bool {
        if ($this->db->columnExists(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'nextDueDate')) {
            $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'nextDueDate');
        }

        return true;
    }

    public function safeDown(): bool {
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'nextDueDate', $this->dateTime()->null());

        return true;
    }
}
