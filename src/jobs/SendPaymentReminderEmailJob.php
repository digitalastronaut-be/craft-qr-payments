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
use craft\web\View;

use digitalastronaut\craftqrpayments\elements\Payment;

use RuntimeException;

/**
 * Class SendPaymentReminderEmailJob
 *
 * The payer-facing nudge — distinct from the admin-facing
 * `SendPaymentsSummaryEmailJob` digest. Queued once per qualifying
 * payment by `ReminderController::actionSend()`: once when it's exactly
 * 7 days from its due date, and again on the day it first becomes
 * expired. The template branches on `payment.getStatus()` (same `if`/
 * `else` pattern `_pay.twig` uses) to show "due soon" vs "overdue" copy
 * from a single file, rather than needing two separate templates.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class SendPaymentReminderEmailJob extends SendTemplatedEmailJob {
    public int $paymentId;

    private ?Payment $payment = null;

    /**
     * See `SendPaymentCreatedEmailJob::__sleep()` — same reason: excludes
     * the lazily-cached `$payment` (and its own cached, closure-bearing
     * custom field layout) from serialization, since `Queue::push()`
     * populates it via `getDescription()` before the job is serialized.
     *
     * @return string[]
     */
    public function __sleep(): array {
        return ['paymentId'];
    }

    /**
     * @return string
     */
    protected function getTemplate(): string {
        $override = $this->getPayment()->getType()->paymentReminderTemplate;

        return $override !== '' ? $override : 'qr-payments/emails/_paymentReminder';
    }

    /**
     * @return string
     */
    protected function getTemplateMode(): string {
        $override = $this->getPayment()->getType()->paymentReminderTemplate;

        return $override !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;
    }

    /**
     * @return array
     */
    protected function getTemplateVariables(): array {
        return ['payment' => $this->getPayment()];
    }

    /**
     * @return string|null
     */
    protected function getRecipient(): ?string {
        return $this->getPayment()->getNotificationEmail() ?: null;
    }

    /**
     * @return string
     */
    protected function getSubject(): string {
        $payment = $this->getPayment();

        return $payment->getStatus() === Payment::STATUS_EXPIRED
            ? Craft::t('qr-payments', 'Your payment {reference} is overdue', ['reference' => $payment->remittanceReference])
            : Craft::t('qr-payments', 'Your payment {reference} is due soon', ['reference' => $payment->remittanceReference]);
    }

    /**
     * @return Payment
     */
    private function getPayment(): Payment {
        if ($this->payment !== null) return $this->payment;

        $payment = Payment::find()->id($this->paymentId)->one();

        if (!$payment) throw new RuntimeException("No payment found with id {$this->paymentId}.");

        return $this->payment = $payment;
    }
}
