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

namespace digitalastronaut\craftqrpayments\jobs;

use Craft;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;
use craft\web\View;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\QrPayments;

use RuntimeException;

/**
 * Class SendPaymentsSummaryEmailJob
 *
 * The admin-facing digest — renamed from `SendPaymentReminderEmailJob` to
 * free up that name for the payer-facing `SendPaymentReminderEmailJob`
 * (sent 7 days before a payment is due, and again the day it expires).
 * This one is queued once per `PaymentType` per `ReminderController::
 * actionSend()` run (only for types that have both recipients and at
 * least one payment needing action), carrying that type's id and every
 * recipient's email address. The digest content is the same for everyone
 * subscribed to this type, so the template is rendered once here and sent
 * individually to each recipient — only `$paymentTypeId` and the
 * recipient emails cross the queue boundary, never the `PaymentType`/
 * `Payment` objects themselves (queue jobs are serialized), so both are
 * re-fetched fresh at execute().
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SendPaymentsSummaryEmailJob extends BaseJob {
    public int $paymentTypeId;

    /** @var string[] */
    public array $recipientEmails = [];

    /**
     * @param QueueInterface $queue
     * @return void
     */
    public function execute($queue): void {
        $type = QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeById($this->paymentTypeId);

        if ($type === null || !$this->recipientEmails) return;

        $payments = Payment::find()
            ->typeId($this->paymentTypeId)
            // Overpaid isn't included here — that's money we owe the
            // customer back, not a balance still owed to us.
            ->status([Payment::STATUS_PENDING, Payment::STATUS_AWAITING_CONFIRMATION, Payment::STATUS_EXPIRED, Payment::STATUS_PARTIAL])
            ->orderBy(['dueDate' => SORT_ASC])
            ->all();

        if (!$payments) return;

        $override = $type->paymentsSummaryTemplate;
        $template = $override !== '' ? $override : 'qr-payments/emails/_paymentsSummary';
        $mode = $override !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;

        $html = Craft::$app->getView()->renderTemplate($template, ['payments' => $payments, 'paymentType' => $type], $mode);

        $subject = Craft::t('qr-payments', '{count} “{type}” payment(s) still need to be processed', [
            'count' => count($payments),
            'type' => $type->name,
        ]);

        foreach ($this->recipientEmails as $email) {
            $message = Craft::$app->getMailer()->compose()
                ->setTo($email)
                ->setSubject($subject)
                ->setHtmlBody($html);

            if (!$message->send()) {
                throw new RuntimeException("Mailer refused to send \"{$template}\" to {$email}.");
            }
        }
    }

    /**
     * @return string|null
     */
    protected function defaultDescription(): ?string {
        $type = QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeById($this->paymentTypeId);

        return Craft::t('qr-payments', 'Sending payments summary email for “{type}”', [
            'type' => $type?->name ?? $this->paymentTypeId,
        ]);
    }
}
