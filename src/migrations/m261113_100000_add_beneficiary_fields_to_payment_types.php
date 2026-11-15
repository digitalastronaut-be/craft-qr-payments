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
 * Class m261113_100000_add_beneficiary_fields_to_payment_types
 *
 * Lets each PaymentType optionally override the plugin's global
 * beneficiary/IBAN/BIC — e.g. webshop orders settle to one bank account,
 * course bookings to another. All three default to `''` (not `NULL` —
 * matching `PaymentType`'s non-nullable `string` properties, same as the
 * plugin's `Settings` model): blank means "use the plugin's global
 * `Settings` value", the fallback `PaymentsService::createPayment()`
 * already applies.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0-beta
 */
class m261113_100000_add_beneficiary_fields_to_payment_types extends Migration {
    public function safeUp(): bool {
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'beneficiaryName', $this->string(70)->notNull()->defaultValue('')->after('name'));
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'iban', $this->string(34)->notNull()->defaultValue('')->after('beneficiaryName'));
        $this->addColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'bic', $this->string(11)->notNull()->defaultValue('')->after('iban'));

        return true;
    }

    public function safeDown(): bool {
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'bic');
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'iban');
        $this->dropColumn(Table::QR_PAYMENTS_PAYMENTTYPES, 'beneficiaryName');

        return true;
    }
}
