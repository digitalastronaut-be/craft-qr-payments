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

use nystudio107\pluginvite\helpers\FileHelper;
use nystudio107\pluginvite\services\VitePluginService;

/**
 * Class QrPaymentsViteService
 *
 * `VitePluginService::init()` builds `$manifestPath` off `self::MANIFEST_FILE_NAME`
 * rather than `static::MANIFEST_FILE_NAME`, so it always resolves to the parent
 * class's constant and ignores this override. Recompute it here instead.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class QrPaymentsViteService extends VitePluginService {
    protected const MANIFEST_FILE_NAME = '.vite/manifest.json';

    /**
     * @return void
     */
    public function init(): void {
        parent::init();

        if ($this->assetClass) {
            $bundle = new $this->assetClass();
            $this->manifestPath = FileHelper::createUrl($bundle->sourcePath, static::MANIFEST_FILE_NAME);
        }
    }
}
