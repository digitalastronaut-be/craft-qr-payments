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
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\events\RequestSubscriptionEvent;
use digitalastronaut\craftqrpayments\exceptions\DuplicateSubscriptionException;
use digitalastronaut\craftqrpayments\QrPayments;

use Throwable;
use yii\base\Action;
use yii\web\BadRequestHttpException;

/**
 * Class SubscriptionsController
 *
 * Mostly handles what Craft's own generic `elements/*` actions don't:
 * `actionIndex()` (the native element index page) and
 * `actionActivate()`/`actionCancel()` (status transitions through the
 * service's own methods, never a raw posted `status`).
 * Creation (`elements/create`), editing, and saving all go through
 * Craft's generic actions — `Subscription::hasDrafts()` is true, so "New
 * subscription" creates an unpublished draft the same way a new Entry
 * does, rather than needing a bespoke create form. The exception is
 * `actionRequest()`, the one public/anonymous action here — a front-end
 * "subscribe" flow has no business creating an unpublished draft and
 * walking it through Craft's own publish flow, so it just saves a real
 * `requested` subscription directly, same as `PaymentsController::actionRequest()`
 * does for a one-off `Payment`.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SubscriptionsController extends Controller {
    public const string PERMISSION_MANAGE_SUBSCRIPTIONS = 'qr-payments:manage-subscriptions';

    /**
     * Fired by `actionRequest()` before a subscription is created, so
     * site-level code can enforce its own eligibility rules — see
     * `RequestSubscriptionEvent`.
     */
    public const string EVENT_BEFORE_REQUEST_SUBSCRIPTION = 'beforeRequestSubscription';

    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = ['request'];

    /**
     * @param Action $action
     * @return bool
     */
    public function beforeAction($action): bool {
        if ($action->id !== 'request') $this->requirePermission(self::PERMISSION_MANAGE_SUBSCRIPTIONS);

        return parent::beforeAction($action);
    }

    /**
     * Creates a new `requested` subscription for the posted subscription
     * type and immediately generates its first linked `Payment`, then
     * redirects to that payment's pay page — same shape as
     * `PaymentsController::actionRequest()`, just for a recurring
     * subscription instead of a one-off payment. Supports the same
     * optional signed `payer` token `PaymentsController::actionRequest()`
     * does, for requesting on behalf of someone else.
     *
     * @return Response|null
     */
    public function actionRequest(): ?Response {
        $this->requirePostRequest();

        $token = $this->resolveRequestToken();
        if ($token === false) return $this->asFailure(Craft::t('qr-payments', 'This request has expired. Please try again.'));

        $handle = $token['typeHandle'] ?? (string) $this->request->getBodyParam('subscriptionTypeHandle', '');
        $type = $handle !== '' ? QrPayments::getInstance()->getSubscriptionTypes()->getSubscriptionTypeByHandle($handle) : null;

        if ($type === null) return $this->asFailure(Craft::t('qr-payments', 'Invalid subscription type.'));

        $userId = $token['userId'] ?? Craft::$app->getUser()->getIdentity()?->id;
        $user = $userId !== null ? Craft::$app->getUsers()->getUserById($userId) : null;

        if (!$type->isRequestableBy($user)) {
            return $this->asFailure(Craft::t('qr-payments', 'This subscription type isn’t available to you.'));
        }

        $email = $this->request->getBodyParam('email', '');
        if (!$email) return $this->asFailure(Craft::t('qr-payments', 'Email is required.'));

        $event = new RequestSubscriptionEvent(['userId' => $userId, 'email' => $email, 'subscriptionType' => $type]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_REQUEST_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_BEFORE_REQUEST_SUBSCRIPTION, $event);
        }

        if (!$event->isValid) {
            return $this->asFailure($event->error ?? Craft::t('qr-payments', 'Could not create the subscription.'));
        }

        try {
            $payment = QrPayments::getInstance()->getSubscriptions()->requestSubscription($type, $email, $userId);
        } catch (DuplicateSubscriptionException $error) {
            return $this->asFailure($error->getMessage());
        } catch (Throwable $error) {
            Craft::error($error->getMessage(), __METHOD__);

            return $this->asFailure(Craft::t('qr-payments', 'Could not create the subscription.'));
        }

        $route = QrPayments::getInstance()->getSettings()->paymentRoute;

        return $this->asSuccess(
            Craft::t('qr-payments', 'Subscription created.'),
            redirect: UrlHelper::siteUrl("{$route}/{$payment->uid}/pay"),
        );
    }

    /**
     * @return array{userId: int|null, typeHandle: string}|false|null The
     * resolved token, `false` if a `payer` param was posted but didn't
     * check out (bad signature or expired), or `null` if none was posted
     * at all.
     */
    private function resolveRequestToken(): array|false|null {
        $raw = (string) $this->request->getBodyParam('payer', '');
        if ($raw === '') return null;

        return QrPayments::getInstance()->getRequestTokens()->resolve($raw) ?? false;
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
        $userId = Craft::$app->getUser()->getIdentity()?->id;

        if (!QrPayments::getInstance()->getSubscriptions()->activate($subscription, $userId)) {
            return $this->asFailure(Craft::t('qr-payments', 'Couldn’t activate subscription.'));
        }

        return $this->asSuccess(Craft::t('qr-payments', 'Subscription activated.'), redirect: $subscription->getCpEditUrl());
    }

    /**
     * @return Response
     * @throws BadRequestHttpException if the posted `elementId` doesn't match a real subscription
     */
    public function actionCancel(): Response {
        $subscription = $this->subscriptionFromRequest();
        $userId = Craft::$app->getUser()->getIdentity()?->id;

        if (!QrPayments::getInstance()->getSubscriptions()->cancel($subscription, $userId)) {
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
