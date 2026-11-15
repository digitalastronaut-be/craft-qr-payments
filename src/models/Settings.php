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

namespace digitalastronaut\craftqrpayments\models;

use Craft;
use craft\base\Model;

/**
 * Class Settings
 *
 * What's genuinely site-wide (not per `PaymentType`) lives here: the CP
 * nav label, and the public pay-page URL namespace, which is a single
 * site-routing decision shared by every payment regardless of type.
 * Everything else — beneficiary/IBAN/BIC, due date, reference prefix, the
 * pay/created/status-changed/reminder templates, and the reminder
 * recipients — lives on `PaymentType` instead, since it's a plain
 * database row (unlike this project-config-backed model) and so stays
 * editable on a live site even with `allowAdminChanges` off. See
 * SETTINGS_CLEANUP_PLAN.md.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class Settings extends Model {
    public string $pluginName = 'QR Payments';

    /**
     * @var string Site route segment the public "pay" and "status" pages
     * are served under (e.g. `betalen` instead of the default
     * `qr-payments/payments`), so the plugin handle doesn't have to show
     * up in a customer-facing production URL. No leading or trailing slash.
     */
    public string $paymentRoute = 'qr-payments/payments';

    /**
     * @return array
     */
    protected function defineRules(): array {
        return array_merge(parent::defineRules(), [
            [['pluginName', 'paymentRoute'], 'string'],
            [['paymentRoute'], 'required'],
            [
                ['paymentRoute'], 'match',
                'pattern' => '/^[a-zA-Z0-9_-]+(\/[a-zA-Z0-9_-]+)*$/',
                'message' => Craft::t('qr-payments', 'This can only contain letters, numbers, hyphens, and underscores, grouped into segments with a “/”. No leading or trailing slash.'),
            ],
        ]);
    }
}
