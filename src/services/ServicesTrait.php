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

namespace digitalastronaut\craftqrpayments\services;

use craft\helpers\App;

use digitalastronaut\craftqrpayments\web\assets\admin\AdminAssetBundle;

use nystudio107\pluginvite\services\VitePluginService;

/**
 * Class ServicesTrait
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
trait ServicesTrait {
    /**
     * @return array
     */
    public static function config(): array {
        return [
            'components' => [
                'epcQr' => EpcQrService::class,
                'payments' => PaymentsService::class,
                'vite' => [
                    'class' => QrPaymentsViteService::class,
                    'assetClass' => AdminAssetBundle::class,
                    'useForAllRequests' => true,
                    'pluginDevServerEnvVar' => 'QR_PAYMENTS_VITE_DEVSERVER',
                    'useDevServer' => true,
                    'checkDevServer' => true,
                    'devServerInternal' => 'http://localhost:3002',
                    'devServerPublic' => App::env('PRIMARY_SITE_URL') . ':3003',
                    'errorEntry' => 'src/web/assets/admin/admin.js',
                ],
            ],
        ];
    }

    /**
     * @return VitePluginService
     */
    public function getVite(): VitePluginService {
        return $this->get('vite');
    }

    /**
     * @return EpcQrService
     */
    public function getEpcQr(): EpcQrService {
        return $this->get('epcQr');
    }

    /**
     * @return PaymentsService
     */
    public function getPayments(): PaymentsService {
        return $this->get('payments');
    }
}
