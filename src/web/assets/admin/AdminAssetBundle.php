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

namespace digitalastronaut\craftqrpayments\web\assets\admin;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Class AdminAssetBundle
 *
 * Doesn't declare `$css`/`$js` itself — actually loading the built (or, in
 * dev mode, live) entry is `VitePluginService::register()`'s job, called
 * from `PluginTrait::registerCpAssets()`. This bundle's only purpose is to
 * give the Vite service a `sourcePath` to publish and resolve `/cpresources/`
 * URLs (manifest.json, hashed asset paths) against once built.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class AdminAssetBundle extends AssetBundle {
    /**
     * @return void
     */
    public function init(): void {
        $this->sourcePath = '@digitalastronaut/craftqrpayments/web/assets/dist';
        $this->depends = [CpAsset::class];

        parent::init();
    }
}
