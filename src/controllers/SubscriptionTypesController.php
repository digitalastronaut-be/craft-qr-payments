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
use digitalastronaut\craftqrpayments\services\EmailPreviewService;

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
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
        ]);
    }

    /**
     * Renders one of the five subscription emails against fake test
     * data, for the live preview box under its field on the Emails tab
     * — see `EmailPreviewService`. Loaded straight into an `<iframe>` by
     * `_edit.twig`, so this returns the rendered email itself rather
     * than JSON. Shared with `PaymentTypesController::actionPreviewEmail()`
     * only through `EmailPreviewService`, which already dispatches on
     * `emailKey` regardless of which kind of type it belongs to.
     *
     * @return Response
     * @throws BadRequestHttpException if `emailKey` isn't one of `EmailPreviewService`'s known template-override attributes
     */
    public function actionPreviewEmail(): Response {
        $emailKey = $this->request->getRequiredQueryParam('emailKey');

        if (!EmailPreviewService::isValidEmailKey($emailKey)) {
            throw new BadRequestHttpException("Invalid email key: {$emailKey}");
        }

        $template = $this->request->getQueryParam('template') ?: null;
        $typeName = $this->request->getQueryParam('typeName') ?: null;
        $emailPreview = QrPayments::getInstance()->getEmailPreview();

        $html = $template !== null
            ? $emailPreview->renderCustom($emailKey, $template, $typeName)
            : $emailPreview->renderDefault($emailKey, $typeName);

        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');

        return $this->asRaw($html);
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
        $subscriptionType->subscriptionRequestedTemplate = $this->request->getBodyParam('subscriptionRequestedTemplate', '');
        $subscriptionType->subscriptionCancelledTemplate = $this->request->getBodyParam('subscriptionCancelledTemplate', '');
        $subscriptionType->subscriptionExpiredTemplate = $this->request->getBodyParam('subscriptionExpiredTemplate', '');
        $subscriptionType->subscriptionActivatedTemplate = $this->request->getBodyParam('subscriptionActivatedTemplate', '');
        $subscriptionType->subscriptionExpiringTemplate = $this->request->getBodyParam('subscriptionExpiringTemplate', '');

        // `checkboxSelectField` posts a plain-string placeholder when
        // nothing's checked, so "nothing selected" can be told apart
        // from "field not submitted" — same quirk `PaymentTypesController::
        // actionSave()` handles for its own `reminderRecipients`/`requestableBy`.
        $requestableBy = $this->request->getBodyParam('requestableBy', []);
        $subscriptionType->requestableBy = is_array($requestableBy)
            ? $requestableBy
            : ($requestableBy === '' ? [] : (array) $requestableBy);

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
            // Craft.VueAdminTable's delete button only shows the fail
            // message (and skips the success notice + table reload) when
            // the request actually rejects — a 200 response with a
            // `success: false` body still resolves, so this must set a
            // non-2xx status via asFailure(), not a plain asJson() array.
            return $this->asFailure(Craft::t('qr-payments', 'Couldn’t delete “{name}” — subscriptions still use it.', [
                'name' => $subscriptionType->name,
            ]));
        }

        return $this->asSuccess(Craft::t('qr-payments', '“{name}” deleted.', ['name' => $subscriptionType->name]));
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
