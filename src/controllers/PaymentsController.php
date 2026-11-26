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
use craft\elements\User;
use craft\helpers\MoneyHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Response;
use craft\web\View;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\events\RequestPaymentEvent;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\QrPayments;
use digitalastronaut\craftqrpayments\web\assets\frontend\FrontendAssetBundle;

use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Class PaymentsController
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class PaymentsController extends Controller {
    /**
     * Fired by `actionRequest()` before a payment is created, so
     * site-level code can enforce its own eligibility rules — see
     * `RequestPaymentEvent`.
     */
    public const string EVENT_BEFORE_REQUEST_PAYMENT = 'beforeRequestPayment';

    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = ['request', 'pay', 'report-paid'];

    /**
     * @return Response
     */
    public function actionIndex(): Response {
        $this->requirePermission('qr-payments:access-payments');

        return $this->renderTemplate('qr-payments/pages/_paymentHistory.twig');
    }

    /**
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionMarkAsPaid(): ?Response {
        $this->requirePostRequest();
        $this->requirePermission('qr-payments:access-payments');

        $payment = $this->paymentById((int) $this->request->getRequiredBodyParam('elementId'));
        $user = $this->userAuthorizedToSave($payment);
        $redirect = $payment->getCpEditUrl();

        $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYMENT, $payment->getAmount(), $user->id);

        if (!Craft::$app->getElements()->saveElement($payment)) {
            $message = $payment->getFirstError('statusHistory') ?? Craft::t('qr-payments', 'Couldn’t mark the payment as paid.');

            return $this->asFailure($message);
        }

        return $this->asSuccess(Craft::t('qr-payments', 'Payment marked as paid.'), redirect: $redirect);
    }

    /**
     * @param int $elementId
     * @param string $type One of the HISTORY_TYPE_* constants.
     * @return Response
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionHistoryEntryModal(int $elementId, string $type): Response {
        $this->requirePermission('qr-payments:access-payments');

        $labels = self::postableHistoryTypeLabels();

        if (!isset($labels[$type])) throw new BadRequestHttpException('Invalid history entry type.');

        $payment = $this->paymentById($elementId);
        $this->userAuthorizedToSave($payment);

        return $this->asCpModal()
            ->action('qr-payments/payments/save-history-entry')
            ->submitButtonLabel($labels[$type])
            ->formAttributes(['class' => ['qrp-history-entry-modal']])
            ->contentTemplate('qr-payments/components/_historyEntryForm.twig', [
                'elementId' => $payment->id,
                'type' => $type,
                'title' => $labels[$type],
                'showAmount' => $type !== Payment::HISTORY_TYPE_CANCELLATION,
            ]);
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionSaveHistoryEntry(): ?Response {
        $this->requirePostRequest();
        $this->requirePermission('qr-payments:access-payments');

        $type = (string) $this->request->getRequiredBodyParam('type');

        if (!isset(self::postableHistoryTypeLabels()[$type])) {
            throw new BadRequestHttpException('Invalid history entry type.');
        }

        $payment = $this->paymentById((int) $this->request->getRequiredBodyParam('elementId'));
        $user = $this->userAuthorizedToSave($payment);

        $amount = null;

        if ($type !== Payment::HISTORY_TYPE_CANCELLATION) {
            $amount = Payment::parseMoney($this->request->getBodyParam('amount'));

            if ($amount === null || !$amount->isPositive()) {
                return $this->asFailure(Craft::t('qr-payments', 'Please enter an amount greater than 0.'));
            }
        }

        $note = trim((string) $this->request->getBodyParam('note', ''));

        $payment->pushStatusHistoryEntry($type, $amount, $user->id, $note !== '' ? $note : null);

        if (!Craft::$app->getElements()->saveElement($payment)) {
            $message = $payment->getFirstError('statusHistory') ?? Craft::t('qr-payments', 'Couldn’t save the payment.');

            return $this->asFailure($message);
        }

        return $this->asSuccess(Craft::t('qr-payments', 'Payment updated.'));
    }

    /**
     * Creates a one-off `Payment` for the posted type/amount/email, either
     * for whoever's logged in (or a guest), or — via an optional signed
     * `payer` token (see `RequestTokensService`) — on behalf of someone
     * else entirely, as a site decides to issue one.
     *
     * @return Response|null
     */
    public function actionRequest(): ?Response {
        $this->requirePostRequest();

        $token = $this->resolveRequestToken();
        if ($token === false) return $this->asFailure(Craft::t('qr-payments', 'This request has expired. Please try again.'));

        $type = $this->resolveRequestedPaymentType($token);
        if ($type === null) return $this->asFailure(Craft::t('qr-payments', 'Invalid payment type.'));

        $userId = $token['userId'] ?? Craft::$app->getUser()->getIdentity()?->id;
        $user = $userId !== null ? Craft::$app->getUsers()->getUserById($userId) : null;

        if (!$type->isRequestableBy($user)) {
            return $this->asFailure(Craft::t('qr-payments', 'This payment type isn’t available to you.'));
        }

        $amount = $type->getAmount() !== null
            ? (float) MoneyHelper::toDecimal($type->getAmount())
            : $this->hashedAmountFromRequest();
        if ($amount === null) return $this->asFailure(Craft::t('qr-payments', 'Invalid amount.'));

        $email = $this->request->getBodyParam('email', '');
        if (!$email) return $this->asFailure(Craft::t('qr-payments', 'Email is required.'));

        $event = new RequestPaymentEvent(['userId' => $userId, 'email' => $email, 'paymentType' => $type]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_REQUEST_PAYMENT)) $this->trigger(self::EVENT_BEFORE_REQUEST_PAYMENT, $event);
        if (!$event->isValid) return $this->asFailure($event->error ?? Craft::t('qr-payments', 'Could not create the payment.'));

        try {
            $payment = QrPayments::getInstance()
                ->getPayments()
                ->createPayment($type, $amount, $email, userId: $userId);

        } catch (Throwable $error) {
            Craft::error($error->getMessage(), __METHOD__);

            return $this->asFailure(Craft::t('qr-payments', 'Could not create the payment.'));
        }

        $route = QrPayments::getInstance()->getSettings()->paymentRoute;

        return $this->asSuccess(
            Craft::t('qr-payments', 'Payment created.'),
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

        return QrPayments::getInstance()
            ->getRequestTokens()
            ->resolve($raw) ?? false;
    }

    /**
     * Resolves which `PaymentType` this request is for: the token's own
     * type handle when there is one (it supersedes a plain `typeHandle`
     * body param — the token, once present, is authoritative), else a
     * plain `typeHandle` body param, else `getDefaultPaymentType()` —
     * today's behavior, unchanged for every existing caller that posts
     * neither.
     *
     * @param array{userId: int|null, typeHandle: string}|null $token
     * @return PaymentType|null
     */
    private function resolveRequestedPaymentType(?array $token): ?PaymentType {
        if ($token !== null) return QrPayments::getInstance()
            ->getPaymentTypes()
            ->getPaymentTypeByHandle($token['typeHandle']);

        $handle = (string) $this->request->getBodyParam('typeHandle', '');

        return $handle !== ''
            ? QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeByHandle($handle)
            : QrPayments::getInstance()->getPaymentTypes()->getDefaultPaymentType();
    }

    /**
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     */
    public function actionPay(string $uid): Response {
        $payment = $this->paymentByUid($uid);

        [$template, $variables, $mode] = $this->payTemplateVariables($payment);

        return $this->renderTemplate($template, $variables, $mode);
    }

    /**
     * Lets the payer flag, from the public pay page, that they’ve already
     * transferred the money — moving the payment to `awaiting_confirmation`
     * until the site owner manually verifies and confirms it.
     *
     * `uid` is read from the body rather than bound as an action parameter:
     * the form posts via the `action` hidden-input override, which bypasses
     * URL-rule routing entirely, so there's no route-matched `uid` for Yii
     * to bind here (see `actionRequest()` for the same pattern).
     *
     * @return Response
     */
    public function actionReportPaid(): Response {
        $this->requirePostRequest();

        $payment = $this->paymentByUid($this->request->getRequiredBodyParam('uid'));

        if ($payment->canReportPaidByPayer()) {
            $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYER_REPORTED, null, null);

            if (!Craft::$app->getElements()->saveElement($payment)) {
                $this->setFailFlash(Craft::t('qr-payments', 'Couldn’t update the payment status.'));

                return $this->redirect($payment->getUrl());
            }
        }

        return $this->redirect($payment->getUrl());
    }

    /**
     * @param Payment $payment
     * @return array{0: string, 1: array, 2: string}
     */
    private function payTemplateVariables(Payment $payment): array {
        $variables = [
            'payment' => $payment,
            'qrCodeDataUri' => $payment->getPayableQrCodeDataUri(),
        ];

        $override = $payment->getType()->payTemplate;

        if ($override !== '') return [$override, $variables, View::TEMPLATE_MODE_SITE];

        // Only the bundled default template wants this bundle's
        // `html { background-color }` and card styling — a custom
        // `payTemplate` has its own site layout/look.
        Craft::$app->view->registerAssetBundle(FrontendAssetBundle::class);
        QrPayments::getInstance()->getVite()->register('src/web/assets/frontend/frontend.js');

        return ['qr-payments/components/_pay.twig', $variables, View::TEMPLATE_MODE_CP];
    }

    /**
     * @return float|null
     */
    private function hashedAmountFromRequest(): ?float {
        $hashed = (string) $this->request->getBodyParam('amount', '');
        $raw = $hashed !== '' ? Craft::$app->getSecurity()->validateData($hashed) : false;

        if ($raw === false || !is_numeric($raw)) return null;

        return (float) $raw;
    }

    /**
     * @param string $uid
     * @return Payment
     * @throws NotFoundHttpException
     */
    private function paymentByUid(string $uid): Payment {
        $payment = Payment::find()->uid($uid)->status(null)->one();

        if (!$payment instanceof Payment) throw new NotFoundHttpException('Payment not found');

        return $payment;
    }

    /**
     * @param int $id
     * @return Payment
     * @throws NotFoundHttpException
     */
    private function paymentById(int $id): Payment {
        $payment = Payment::find()->id($id)->status(null)->one();

        if (!$payment instanceof Payment) throw new NotFoundHttpException('Payment not found');

        return $payment;
    }

    /**
     * @param Payment $payment
     * @return User
     * @throws ForbiddenHttpException
     */
    private function userAuthorizedToSave(Payment $payment): User {
        $user = static::currentUser();

        if ($user === null || !$payment->canSave($user)) {
            throw new ForbiddenHttpException('User not authorized to save this payment.');
        }

        return $user;
    }

    /**
     * The subset of `Payment::historyTypes()` an admin can manually add
     * through `actionHistoryEntryModal()`/`actionSaveHistoryEntry()` —
     * deliberately narrower than the full registry, which also includes
     * system-only entries (`HISTORY_TYPE_CREATED`, stamped automatically
     * on a payment's first save) that have no business being posted here.
     *
     * @return array<string, string> Type handle => button/modal label
     */
    private static function postableHistoryTypeLabels(): array {
        return [
            Payment::HISTORY_TYPE_PAYMENT => Craft::t('qr-payments', 'Add payment'),
            Payment::HISTORY_TYPE_REFUND => Craft::t('qr-payments', 'Refund'),
            Payment::HISTORY_TYPE_CANCELLATION => Craft::t('qr-payments', 'Cancel payment'),
        ];
    }
}
