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
 * Class m261117_090000_add_amount_to_payment_types
 *
 * Lets a `PaymentType` carry its own fixed price, mirroring
 * `SubscriptionType::$amount` — nullable, unlike that one, since most
 * existing payment types are a variable amount supplied by the caller
 * (e.g. a hashed amount posted to `PaymentsController::actionRequest()`).
 * A type with a fixed amount lets a project point a form straight at
 * that action with no custom PHP at all: no price to compute, nothing to
 * sign.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class m261117_090000_add_amount_to_payment_types extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'amount', $this->decimal(12, 2)->null()->after('handle'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'amount');

        return true;
    }
}
