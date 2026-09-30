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
use craft\elements\User;
use craft\models\FieldLayout;
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftqrpayments\elements\Payment;
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
     * @param FieldLayout|null $fieldLayout re-populated on a failed save, via actionSave()'s route params
     * @return Response
     */
    public function actionIndex(?PluginInterface $plugin = null, ?FieldLayout $fieldLayout = null): Response {
        $this->requireAdmin(false);

        $plugin ??= QrPayments::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('qr-payments/pages/_settings.twig', [
            'plugin' => $plugin,
            'settings' => $settings,
            'fieldLayout' => $fieldLayout ?? Payment::getCustomFieldLayout(),
            'reminderRecipientUsers' => $settings->reminderRecipients ? User::find()->id($settings->reminderRecipients)->status(null)->all() : [],
        ]);
    }

    /**
     * Saves the plugin's simple settings (beneficiary/IBAN/BIC) and the
     * field layout designer's custom Payment fields in one submission,
     * replacing the core `plugins/save-plugin-settings` action so the
     * posted field layout config can also be assembled and persisted.
     *
     * @return Response|null
     */
    public function actionSave(): ?Response {
        $this->requireAdmin(false);
        $this->requirePostRequest();

        $plugin = QrPayments::getInstance();

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = Payment::class;

        $settingsSaved = Craft::$app->getPlugins()->savePluginSettings($plugin, $this->request->getBodyParam('settings', []));
        $fieldLayoutSaved = Craft::$app->getFields()->saveLayout($fieldLayout);

        if (!$settingsSaved || !$fieldLayoutSaved) {
            $this->setFailFlash(Craft::t('qr-payments', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'plugin' => $plugin,
                'fieldLayout' => $fieldLayout,
            ]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('qr-payments', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
