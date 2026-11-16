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
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use digitalastronaut\craftqrpayments\helpers\Table;

/**
 * Class m261115_160000_remove_subscription_paused_status
 *
 * Drops the `paused` subscription status: functionally it was always
 * identical to `cancelled` (excluded from `getDueSubscriptions()`'s
 * billing cron, never auto-reactivated by a late payment clearing), the
 * only difference being that `cancel()` stamps `endedAt` and `pause()`
 * didn't. Folds any existing `paused` row into `cancelled` so nothing is
 * left holding a status value the code no longer recognizes.
 *
 * Irreversible by design: `safeDown()` can't tell a `paused` subscription
 * apart from one that was genuinely `cancelled` after this has run, same
 * as every other data-fixup migration in this plugin.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261115_160000_remove_subscription_paused_status extends Migration {
    public function safeUp(): bool {
        $this->update(Table::QR_PAYMENTS_SUBSCRIPTIONS, [
            'status' => 'cancelled',
            'endedAt' => Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime()),
        ], ['status' => 'paused']);

        return true;
    }

    public function safeDown(): bool {
        return true;
    }
}
