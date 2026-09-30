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
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;

use yii\base\Event;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\variables\QrPaymentsVariable;
use digitalastronaut\craftqrpayments\web\assets\admin\AdminAssetBundle;
use digitalastronaut\craftqrpayments\widgets\IngredientsCalculator;
use digitalastronaut\craftqrpayments\widgets\RecentPayments;

/**
 * Class PluginTrait
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
trait PluginTrait {
    /**
     * @return void
     */
    protected function registerEvents(): void {
        $this->registerSharedEvents();

        if (Craft::$app->request->isCpRequest) $this->registerCpEvents();
        if (Craft::$app->request->isSiteRequest) $this->registerSiteEvents();
    }

    /**
     * @return void
     */
    protected function registerSharedEvents(): void {
        $this->registerElementTypes();
        $this->registerVariables();
    }

    /**
     * @return void
     */
    protected function registerCpEvents(): void {
        $this->registerPermissions();
        $this->registerCpRoutes();
        $this->registerCpAssets();
        $this->registerWidgetTypes();
    }

    /**
     * @return void
     */
    protected function registerCpAssets(): void {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                $view = Craft::$app->getView();
                $view->registerAssetBundle(AdminAssetBundle::class);

                QrPayments::getInstance()->getVite()->register('src/web/assets/admin/admin.js');
            }
        );
    }

    /**
     * @return void
     */
    protected function registerSiteEvents(): void {
        $this->registerSiteRoutes();
    }

    /**
     * @return void
     */
    protected function registerElementTypes(): void {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Payment::class;
            }
        );
    }

    /**
     * @return void
     */
    protected function registerVariables(): void {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['qrPayments'] = QrPaymentsVariable::class;
            }
        );
    }

    /**
     * @return void
     */
    protected function registerPermissions(): void {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'QR payments',
                    'permissions' => [
                        'qr-payments:access-payments' => [
                            'label' => Craft::t('qr-payments', 'Access payments'),
                        ],
                    ],
                ];
            }
            
        );
    }

    /**
     * @return void
     */
    protected function registerWidgetTypes(): void {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = RecentPayments::class;
                $event->types[] = IngredientsCalculator::class;
            }
        );
    }

    /**
     * @return void
     */
    protected function registerCpRoutes(): void {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['qr-payments'] = 'qr-payments/payments/index';
                $event->rules['qr-payments/payments'] = 'qr-payments/payments/index';
                $event->rules['qr-payments/payments/new'] = 'qr-payments/payments/create';
                $event->rules['qr-payments/payments/process'] = 'qr-payments/process/index';
                $event->rules['qr-payments/payments/<elementId:\d+>'] = 'elements/edit';
                $event->rules['qr-payments/settings'] = 'qr-payments/settings/index';
            }
        );
    }

    /**
     * @return void
     */
    protected function registerSiteRoutes(): void {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $route = $this->getSettings()->paymentRoute;

                $event->rules["{$route}/<uid:[\w-]+>/pay"] = 'qr-payments/payments/pay';
            }
        );
    }
}
