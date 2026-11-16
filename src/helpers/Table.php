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

namespace digitalastronaut\craftqrpayments\helpers;

use craft\db\Table as CraftTable;

/**
 * Class Table
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
abstract class Table extends CraftTable {
    public const string QR_PAYMENTS_PAYMENTS = '{{%qr_payments_payments}}';
    public const string QR_PAYMENTS_PAYMENTTYPES = '{{%qr_payments_paymenttypes}}';
    public const string QR_PAYMENTS_SUBSCRIPTIONS = '{{%qr_payments_subscriptions}}';
    public const string QR_PAYMENTS_SUBSCRIPTIONTYPES = '{{%qr_payments_subscriptiontypes}}';
    public const string QR_PAYMENTS_EMAIL_LOG = '{{%qr_payments_email_log}}';
}
