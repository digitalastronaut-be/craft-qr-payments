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

namespace digitalastronaut\craftqrpayments\web\assets\frontend;

use craft\web\AssetBundle;

/**
 * Class FrontendAssetBundle
 *
 * Public, site-facing counterpart to `AdminAssetBundle` — only registered
 * on `PayController::actionView()`'s "scan to pay" page, not sitewide,
 * since that's the only site template this plugin ships. Like
 * `AdminAssetBundle`, doesn't declare `$css`/`$js` itself: loading the
 * actual entry is `VitePluginService::register()`'s job.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class FrontendAssetBundle extends AssetBundle {
    /**
     * @return void
     */
    public function init(): void {
        $this->sourcePath = '@digitalastronaut/craftqrpayments/web/assets/dist';

        parent::init();
    }
}
