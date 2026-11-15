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

namespace digitalastronaut\craftqrpayments\controllers;

use Craft;
use craft\base\PluginInterface;
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftqrpayments\QrPayments;

/**
 * Class SettingsController
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class SettingsController extends Controller {
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @param PluginInterface|null $plugin
     * @return Response
     */
    public function actionIndex(?PluginInterface $plugin = null): Response {
        $this->requireAdmin(false);

        $plugin ??= QrPayments::getInstance();

        return $this->renderTemplate('qr-payments/pages/_settings.twig', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
        ]);
    }

    /**
     * @return Response|null
     */
    public function actionSave(): ?Response {
        $this->requireAdmin(false);
        $this->requirePostRequest();

        $plugin = QrPayments::getInstance();

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $this->request->getBodyParam('settings', []))) {
            $this->setFailFlash(Craft::t('qr-payments', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams(['plugin' => $plugin]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('qr-payments', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
