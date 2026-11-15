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
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\QrPayments;

use yii\base\Action;
use yii\web\BadRequestHttpException;

/**
 * Class SubscriptionsController
 *
 * Only handles what Craft's own generic `elements/*` actions don't:
 * `actionIndex()` (the native element index page) and
 * `actionActivate()`/`actionPause()`/`actionCancel()` (status transitions
 * through the service's own methods, never a raw posted `status`).
 * Creation (`elements/create`), editing, and saving all go through
 * Craft's generic actions — `Subscription::hasDrafts()` is true, so "New
 * subscription" creates an unpublished draft the same way a new Entry
 * does, rather than needing a bespoke create form.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SubscriptionsController extends Controller {
    public const string PERMISSION_MANAGE_SUBSCRIPTIONS = 'qr-payments:manage-subscriptions';

    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @param Action $action
     * @return bool
     */
    public function beforeAction($action): bool {
        $this->requirePermission(self::PERMISSION_MANAGE_SUBSCRIPTIONS);

        return parent::beforeAction($action);
    }

    /**
     * @return Response
     */
    public function actionIndex(): Response {
        return $this->renderTemplate('qr-payments/pages/_subscriptionIndex.twig');
    }

    /**
     * @return Response
     * @throws BadRequestHttpException if the posted `elementId` doesn't match a real subscription
     */
    public function actionActivate(): Response {
        $subscription = $this->subscriptionFromRequest();

        if (!QrPayments::getInstance()->getSubscriptions()->activate($subscription)) {
            return $this->asFailure(Craft::t('qr-payments', 'Couldn’t activate subscription.'));
        }

        return $this->asSuccess(Craft::t('qr-payments', 'Subscription activated.'), redirect: $subscription->getCpEditUrl());
    }

    /**
     * @return Response
     * @throws BadRequestHttpException if the posted `elementId` doesn't match a real subscription
     */
    public function actionPause(): Response {
        $subscription = $this->subscriptionFromRequest();

        if (!QrPayments::getInstance()->getSubscriptions()->pause($subscription)) {
            return $this->asFailure(Craft::t('qr-payments', 'Couldn’t pause subscription.'));
        }

        return $this->asSuccess(Craft::t('qr-payments', 'Subscription paused.'), redirect: $subscription->getCpEditUrl());
    }

    /**
     * @return Response
     * @throws BadRequestHttpException if the posted `elementId` doesn't match a real subscription
     */
    public function actionCancel(): Response {
        $subscription = $this->subscriptionFromRequest();

        if (!QrPayments::getInstance()->getSubscriptions()->cancel($subscription)) {
            return $this->asFailure(Craft::t('qr-payments', 'Couldn’t cancel subscription.'));
        }

        return $this->asSuccess(Craft::t('qr-payments', 'Subscription cancelled.'), redirect: $subscription->getCpEditUrl());
    }

    /**
     * @return Subscription
     * @throws BadRequestHttpException if the posted `elementId` doesn't match a real subscription
     */
    private function subscriptionFromRequest(): Subscription {
        $this->requirePostRequest();

        $id = (int) $this->request->getRequiredBodyParam('elementId');
        $subscription = Subscription::find()->id($id)->status(null)->one();

        if ($subscription === null) {
            throw new BadRequestHttpException("Invalid subscription ID: {$id}");
        }

        return $subscription;
    }
}
