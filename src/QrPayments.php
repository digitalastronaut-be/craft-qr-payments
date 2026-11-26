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

namespace digitalastronaut\craftqrpayments;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\helpers\UrlHelper;

use digitalastronaut\craftqrpayments\controllers\SubscriptionsController;
use digitalastronaut\craftqrpayments\models\Settings;
use digitalastronaut\craftqrpayments\services\ServicesTrait;

// TODO: reevaluate the payment element
// TODO: recent payemnts widget graph data logic to a query method with from to and also do the bucket and period resolving there.

/**
 * Class QrPayments
 *
 * @method static QrPayments getInstance()
 * @method Settings getSettings()
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class QrPayments extends Plugin {
    use ServicesTrait;
    use PluginTrait;

    public string $schemaVersion = '1.7.4';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @return void
     */
    public function init(): void {
        parent::init();

        $this->registerEvents();
    }

    /**
     * @return array|null
     */
    public function getCpNavItem(): ?array {
        $nav = parent::getCpNavItem();

        $pluginName = $this->getSettings()->pluginName;

        if ($pluginName !== '') $nav['label'] = $pluginName;
        $nav['icon'] = "@digitalastronaut/craftqrpayments/web/icons/qr-code-alt.svg";
        $nav['url'] = 'qr-payments';

        $canAccessPayments = Craft::$app->getUser()->checkPermission('qr-payments:access-payments');

        if ($canAccessPayments) {
            $nav['subnav']['payments'] = [
                'label' => Craft::t('qr-payments', 'Payment history'),
                'url' => 'qr-payments/payments',
            ];
        }

        if (Craft::$app->getUser()->checkPermission(SubscriptionsController::PERMISSION_MANAGE_SUBSCRIPTIONS)) {
            $nav['subnav']['subscriptions'] = [
                'label' => Craft::t('qr-payments', 'Subscriptions'),
                'url' => 'qr-payments/subscriptions',
            ];
        }

        if ($canAccessPayments) {
            $nav['subnav']['process'] = [
                'label' => Craft::t('qr-payments', 'Bulk processing'),
                'url' => 'qr-payments/payments/process',
            ];
        }

        if (Craft::$app->getUser()->getIsAdmin()) {
            // Payment types and Subscription types live inside the
            // Settings area's own secondary sidebar nav (see
            // `_settingsLayout.twig`) rather than here — they're
            // schema/pricing-level config, same category as Settings
            // itself, not a day-to-day nav destination.
            $nav['subnav']['settings'] = [
                'label' => Craft::t('qr-payments', 'Settings'),
                'url' => 'qr-payments/settings',
            ];
        }

        return $nav;
    }

    /**
     * @return mixed
     */
    public function getSettingsResponse(): mixed {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('qr-payments/settings'));
    }

    /**
     * @return Model|null
     */
    protected function createSettingsModel(): ?Model {
        return Craft::createObject(Settings::class);
    }
}
