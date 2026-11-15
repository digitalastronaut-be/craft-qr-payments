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
 * Class m261114_090000_relax_subscription_columns_for_drafts
 *
 * `Subscription::hasDrafts()` is now `true` (see
 * PAYMENT_TYPES_AND_SUBSCRIPTIONS_PLAN.md's "becomes a real element, and
 * creation goes through Craft's own draft flow" revision): a brand-new
 * unpublished draft is saved under `Element::SCENARIO_ESSENTIALS`, which
 * skips the `required` validation rule on `email`/`subscriptionTypeId`/
 * `startedAt` by design (same "required" is scenario-scoped away from
 * ESSENTIALS as `craft\base\Element` itself does for `title`/`slug`) — but
 * the DB columns were still `NOT NULL`, so the very first draft save
 * failed with a DB integrity error before validation ever got a say.
 * Relaxing these three to nullable moves the "required for a real
 * subscription" rule to live entirely in `defineRules()`'s scenario
 * scoping, not the schema.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.7.0-beta
 */
class m261114_090000_relax_subscription_columns_for_drafts extends Migration {
    public function safeUp(): bool {
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'email', $this->string(255)->null());
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', $this->integer()->null());
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'startedAt', $this->dateTime()->null());

        return true;
    }

    public function safeDown(): bool {
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'email', $this->string(255)->notNull());
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', $this->integer()->notNull());
        $this->alterColumn(Table::QR_PAYMENTS_SUBSCRIPTIONS, 'startedAt', $this->dateTime()->notNull());

        return true;
    }
}
