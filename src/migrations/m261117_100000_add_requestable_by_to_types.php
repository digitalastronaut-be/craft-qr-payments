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
 * Class m261117_100000_add_requestable_by_to_types
 *
 * Lets a `PaymentType`/`SubscriptionType` restrict who's allowed to
 * request it through `PaymentsController`/`SubscriptionsController::
 * actionRequest()` — a JSON array of either the literal string `"guest"`
 * or a user group's `uid`. `NULL`/empty means unrestricted (anyone,
 * including guests), which is every existing type's current, unchanged
 * behavior — this ships with no behavior change until a type actually
 * has a restriction configured.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
class m261117_100000_add_requestable_by_to_types extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'requestableBy', $this->json()->null()->after('amount'));
        $this->addColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'requestableBy', $this->json()->null()->after('amount'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'requestableBy');
        $this->dropColumn(Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'requestableBy');

        return true;
    }
}
