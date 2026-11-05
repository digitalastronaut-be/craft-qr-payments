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

    public string $schemaVersion = '1.0.1';
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

        if (Craft::$app->getUser()->checkPermission('qr-payments:access-payments')) {
            $nav['subnav']['payments'] = [
                'label' => Craft::t('qr-payments', 'Payment history'),
                'url' => 'qr-payments/payments',
            ];

            $nav['subnav']['process'] = [
                'label' => Craft::t('qr-payments', 'Process payments'),
                'url' => 'qr-payments/payments/process',
            ];
        }

        if (Craft::$app->getUser()->getIsAdmin()) {
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
