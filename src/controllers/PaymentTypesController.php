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
        ]);
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

        // `_includes/forms/elementSelect.twig` always posts a plain-string
        // placeholder when nothing's selected, so "nothing selected" can be
        // told apart from "field not submitted" — normalize that here
        // before it hits the typed `array` property (same quirk `Settings`
        // handles via a `setAttributes()` override; inlined here instead
        // since this controller already sets every attribute individually).
        $reminderRecipients = $this->request->getBodyParam('reminderRecipients', []);
        $paymentType->reminderRecipients = is_array($reminderRecipients)
            ? $reminderRecipients
            : ($reminderRecipients === '' ? [] : (array) $reminderRecipients);

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
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('qr-payments', 'Couldn’t delete “{name}” — it’s either the only remaining payment type, or payments still use it.', [
                    'name' => $paymentType->name,
                ]),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('qr-payments', '“{name}” deleted.', ['name' => $paymentType->name]),
        ]);
    }
}
