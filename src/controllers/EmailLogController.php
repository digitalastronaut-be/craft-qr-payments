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

use digitalastronaut\craftqrpayments\QrPayments;

use yii\base\Action;
use yii\web\ForbiddenHttpException;

/**
 * Class EmailLogController
 *
 * The one resend action behind the inline resend buttons
 * `StatusHistoryField`/`SubscriptionStatusHistoryField` render (see
 * `EmailLogCell`) — every email this plugin sends belongs to either a
 * `Payment` or a `Subscription`, so either of those two features' own
 * permissions is enough to use this one.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
class EmailLogController extends Controller {
    public $defaultAction = 'resend';

    /**
     * @param Action $action
     * @return bool
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool {
        $user = Craft::$app->getUser();

        if (!$user->checkPermission('qr-payments:access-payments') && !$user->checkPermission(SubscriptionsController::PERMISSION_MANAGE_SUBSCRIPTIONS)) {
            throw new ForbiddenHttpException('User not permitted to perform this action.');
        }

        return parent::beforeAction($action);
    }

    /**
     * @return Response
     */
    public function actionResend(): Response {
        $this->requirePostRequest();

        $logId = (int) $this->request->getRequiredBodyParam('logId');
        $userId = static::currentUser()?->id;

        if (!QrPayments::getInstance()->getEmailLog()->resend($logId, $userId)) {
            return $this->asFailure(Craft::t('qr-payments', 'Couldn’t resend that email.'));
        }

        return $this->asSuccess(Craft::t('qr-payments', 'Email resent.'));
    }
}
