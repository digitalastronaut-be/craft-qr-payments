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
use craft\helpers\Localization;
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Class SubscriptionTypesController
 *
 * Note that every action here requires an admin account, same as
 * `PaymentTypesController` — subscription types are schema/pricing-level
 * config, not a day-to-day permission like `SubscriptionsController`'s
 * `manage-subscriptions`.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class SubscriptionTypesController extends Controller {
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @param Action $action
     * @return bool
     */
    public function beforeAction($action): bool {
        $this->requireAdmin(\in_array($action->id, ['save', 'delete'], true));

        return parent::beforeAction($action);
    }

    /**
     * @return Response
     */
    public function actionIndex(): Response {
        return $this->renderTemplate('qr-payments/pages/_subscriptionTypes/_index.twig', [
            'subscriptionTypes' => QrPayments::getInstance()->getSubscriptionTypes()->getAllSubscriptionTypes(),
        ]);
    }

    /**
     * @param int|null $subscriptionTypeId
     * @param SubscriptionType|null $subscriptionType Re-populated on a failed save, via actionSave()'s route params.
     * @return Response
     * @throws NotFoundHttpException if `$subscriptionTypeId` doesn't match a real subscription type
     */
    public function actionEdit(?int $subscriptionTypeId = null, ?SubscriptionType $subscriptionType = null): Response {
        $subscriptionTypes = QrPayments::getInstance()->getSubscriptionTypes();

        if ($subscriptionType === null) {
            $subscriptionType = $subscriptionTypeId !== null ? $subscriptionTypes->getSubscriptionTypeById($subscriptionTypeId) : new SubscriptionType();

            if ($subscriptionType === null) {
                throw new NotFoundHttpException('Subscription type not found');
            }
        }

        return $this->renderTemplate('qr-payments/pages/_subscriptionTypes/_edit.twig', [
            'subscriptionType' => $subscriptionType,
            'isNew' => $subscriptionType->id === null,
            'paymentTypes' => QrPayments::getInstance()->getPaymentTypes()->getAllPaymentTypes(),
        ]);
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException if the posted `subscriptionTypeId` doesn't match a real subscription type
     */
    public function actionSave(): ?Response {
        $this->requirePostRequest();

        $subscriptionTypes = QrPayments::getInstance()->getSubscriptionTypes();
        $subscriptionTypeId = $this->request->getBodyParam('subscriptionTypeId');

        if ($subscriptionTypeId) {
            $subscriptionType = $subscriptionTypes->getSubscriptionTypeById((int) $subscriptionTypeId);

            if ($subscriptionType === null) {
                throw new BadRequestHttpException("Invalid subscription type ID: {$subscriptionTypeId}");
            }
        } else {
            $subscriptionType = new SubscriptionType();
        }

        $subscriptionType->name = $this->request->getRequiredBodyParam('name');
        $subscriptionType->handle = $this->request->getRequiredBodyParam('handle');
        $subscriptionType->paymentTypeId = (int) $this->request->getRequiredBodyParam('paymentTypeId');
        $subscriptionType->amount = $this->amountFromRequest();
        $subscriptionType->interval = $this->request->getRequiredBodyParam('interval');

        if (!$subscriptionTypes->saveSubscriptionType($subscriptionType)) {
            $this->setFailFlash(Craft::t('qr-payments', 'Couldn’t save subscription type.'));

            Craft::$app->getUrlManager()->setRouteParams(['subscriptionType' => $subscriptionType]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('qr-payments', 'Subscription type saved.'));

        return $this->redirectToPostedUrl($subscriptionType);
    }

    /**
     * Deletes a subscription type. Responds as JSON — this is driven by the
     * `Craft.VueAdminTable` delete action on the index page, not a normal
     * form submission.
     *
     * @return Response
     * @throws BadRequestHttpException if the posted `id` doesn't match a real subscription type
     */
    public function actionDelete(): Response {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $subscriptionTypes = QrPayments::getInstance()->getSubscriptionTypes();
        $id = (int) $this->request->getRequiredBodyParam('id');
        $subscriptionType = $subscriptionTypes->getSubscriptionTypeById($id);

        if ($subscriptionType === null) {
            throw new BadRequestHttpException("Invalid subscription type ID: {$id}");
        }

        if (!$subscriptionTypes->deleteSubscriptionType($subscriptionType)) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('qr-payments', 'Couldn’t delete “{name}” — subscriptions still use it.', [
                    'name' => $subscriptionType->name,
                ]),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('qr-payments', '“{name}” deleted.', ['name' => $subscriptionType->name]),
        ]);
    }

    /**
     * @return float
     */
    private function amountFromRequest(): float {
        $posted = $this->request->getRequiredBodyParam('amount');

        if (!\is_array($posted)) return (float) $posted;

        return (float) Localization::normalizeNumber($posted['value'] ?? '', $posted['locale'] ?? null);
    }
}
