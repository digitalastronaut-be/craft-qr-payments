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
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\QrPayments;
use digitalastronaut\craftqrpayments\services\EmailPreviewService;

use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Class PaymentTypesController
 *
 * Note that every action here requires an admin account; `save`/`delete`
 * additionally require `allowAdminChanges`, matching how Craft's own
 * `EntryTypesController` gates entry type schema changes.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0-beta
 */
class PaymentTypesController extends Controller {
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
        return $this->renderTemplate('qr-payments/pages/_paymentTypes/_index.twig', [
            'paymentTypes' => QrPayments::getInstance()->getPaymentTypes()->getAllPaymentTypes(),
        ]);
    }

    /**
     * @param int|null $paymentTypeId
     * @param PaymentType|null $paymentType Re-populated on a failed save, via actionSave()'s route params.
     * @return Response
     * @throws NotFoundHttpException if `$paymentTypeId` doesn't match a real payment type
     */
    public function actionEdit(?int $paymentTypeId = null, ?PaymentType $paymentType = null): Response {
        $paymentTypes = QrPayments::getInstance()->getPaymentTypes();

        if ($paymentType === null) {
            $paymentType = $paymentTypeId !== null ? $paymentTypes->getPaymentTypeById($paymentTypeId) : new PaymentType();

            if ($paymentType === null) {
                throw new NotFoundHttpException('Payment type not found');
            }
        }

        return $this->renderTemplate('qr-payments/pages/_paymentTypes/_edit.twig', [
            'paymentType' => $paymentType,
            'isNew' => $paymentType->id === null,
            'reminderRecipientUsers' => $paymentType->reminderRecipients ? User::find()->id($paymentType->reminderRecipients)->status(null)->all() : [],
            'paymentStatuses' => Payment::statuses(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
        ]);
    }

    /**
     * Renders one of the four payment emails against fake test data, for
     * the live preview box under its field on the Emails tab — see
     * `EmailPreviewService`. Loaded straight into an `<iframe>` by
     * `_edit.twig`, so this returns the rendered email itself rather than
     * JSON.
     *
     * @return Response
     * @throws BadRequestHttpException if `emailKey` isn't one of `EmailPreviewService`'s four known template-override attributes
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
     * Renders the payer-facing pay page against fake test data, in a
     * given status, for the live preview boxes on the Payment template
     * tab — see `EmailPreviewService::renderPayPagePreview()`. Loaded
     * straight into an `<iframe>` by `_edit.twig`, so this returns the
     * rendered page itself rather than JSON. One box per status is
     * rendered (see `actionEdit()`'s `paymentStatuses`), all pointed at
     * this same action, so trying out a custom `payTemplate` shows every
     * status variant at once rather than one at a time.
     *
     * @return Response
     * @throws BadRequestHttpException if `status` isn't one of `Payment::statuses()`'s keys
     */
    public function actionPreviewPayPage(): Response {
        $status = $this->request->getRequiredQueryParam('status');

        if (!isset(Payment::statuses()[$status])) {
            throw new BadRequestHttpException("Invalid payment status: {$status}");
        }

        $template = $this->request->getQueryParam('template') ?: null;
        $html = QrPayments::getInstance()->getEmailPreview()->renderPayPagePreview($status, $template);

        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');

        return $this->asRaw($html);
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException if the posted `paymentTypeId` doesn't match a real payment type
     */
    public function actionSave(): ?Response {
        $this->requirePostRequest();

        $paymentTypes = QrPayments::getInstance()->getPaymentTypes();
        $paymentTypeId = $this->request->getBodyParam('paymentTypeId');

        if ($paymentTypeId) {
            $paymentType = $paymentTypes->getPaymentTypeById((int) $paymentTypeId);

            if ($paymentType === null) {
                throw new BadRequestHttpException("Invalid payment type ID: {$paymentTypeId}");
            }
        } else {
            $paymentType = new PaymentType();
        }

        $paymentType->name = $this->request->getRequiredBodyParam('name');
        $paymentType->handle = $this->request->getRequiredBodyParam('handle');
        $paymentType->amount = $this->request->getBodyParam('amount');
        $paymentType->defaultDueDateDays = $this->request->getBodyParam('defaultDueDateDays') ?: null;
        $paymentType->beneficiaryName = $this->request->getBodyParam('beneficiaryName', '');
        $paymentType->iban = $this->request->getBodyParam('iban', '');
        $paymentType->bic = $this->request->getBodyParam('bic', '');
        $paymentType->referencePrefix = $this->request->getBodyParam('referencePrefix', '');
        $paymentType->payTemplate = $this->request->getBodyParam('payTemplate', '');
        $paymentType->paymentCreatedTemplate = $this->request->getBodyParam('paymentCreatedTemplate', '');
        $paymentType->paymentStatusChangedTemplate = $this->request->getBodyParam('paymentStatusChangedTemplate', '');
        $paymentType->paymentsSummaryTemplate = $this->request->getBodyParam('paymentsSummaryTemplate', '');
        $paymentType->paymentReminderTemplate = $this->request->getBodyParam('paymentReminderTemplate', '');

        $reminderRecipients = $this->request->getBodyParam('reminderRecipients', []);
        $paymentType->reminderRecipients = is_array($reminderRecipients)
            ? $reminderRecipients
            : ($reminderRecipients === '' ? [] : (array) $reminderRecipients);

        // Same `checkboxSelectField` "nothing checked" quirk as above.
        $requestableBy = $this->request->getBodyParam('requestableBy', []);
        $paymentType->requestableBy = is_array($requestableBy)
            ? $requestableBy
            : ($requestableBy === '' ? [] : (array) $requestableBy);

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = Payment::class;
        $paymentType->setFieldLayout($fieldLayout);

        if (!$paymentTypes->savePaymentType($paymentType)) {
            $this->setFailFlash(Craft::t('qr-payments', 'Couldn’t save payment type.'));

            Craft::$app->getUrlManager()->setRouteParams(['paymentType' => $paymentType]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('qr-payments', 'Payment type saved.'));

        return $this->redirectToPostedUrl($paymentType);
    }

    /**
     * Deletes a payment type. Responds as JSON — this is driven by the
     * `Craft.VueAdminTable` delete action on the index page, not a normal
     * form submission.
     *
     * @return Response
     * @throws BadRequestHttpException if the posted `id` doesn't match a real payment type
     */
    public function actionDelete(): Response {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $paymentTypes = QrPayments::getInstance()->getPaymentTypes();
        $id = (int) $this->request->getRequiredBodyParam('id');
        $paymentType = $paymentTypes->getPaymentTypeById($id);

        if ($paymentType === null) {
            throw new BadRequestHttpException("Invalid payment type ID: {$id}");
        }

        if (!$paymentTypes->deletePaymentType($paymentType)) {
            return $this->asFailure(Craft::t(
                'qr-payments', 
                'Couldn’t delete “{name}” — it’s either the only remaining payment type, or payments still use it.', [
                'name' => $paymentType->name,
            ]));
        }

        return $this->asSuccess(Craft::t('qr-payments', '“{name}” deleted.', ['name' => $paymentType->name]));
    }
}
