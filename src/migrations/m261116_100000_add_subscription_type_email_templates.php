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
 * Class m261116_100000_add_subscription_type_email_templates
 *
 * Adds the same per-type template-override columns `PaymentType` already
 * has (see `m261113_100000_...`/`m261113_120000_...`/
 * `m261113_130000_...`) for `SubscriptionType`'s five bundled emails, so
 * a site can override any of them without touching plugin code — see
 * `SubscriptionType::$subscriptionRequestedTemplate` and friends.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.10.0-beta
 */
class m261116_100000_add_subscription_type_email_templates extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionRequestedTemplate', $this->string(255)->notNull()->defaultValue('')->after('interval'));
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionCancelledTemplate', $this->string(255)->notNull()->defaultValue('')->after('subscriptionRequestedTemplate'));
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionExpiredTemplate', $this->string(255)->notNull()->defaultValue('')->after('subscriptionCancelledTemplate'));
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionActivatedTemplate', $this->string(255)->notNull()->defaultValue('')->after('subscriptionExpiredTemplate'));
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionExpiringTemplate', $this->string(255)->notNull()->defaultValue('')->after('subscriptionActivatedTemplate'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionExpiringTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionActivatedTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionExpiredTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionCancelledTemplate');
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'subscriptionRequestedTemplate');

        return true;
    }
}
