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
 * Class SendPaymentStatusChangedEmailJob
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class SendPaymentStatusChangedEmailJob extends SendTemplatedEmailJob {
    public int $paymentId;
    public string $previousStatus;

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
        return ['paymentId', 'previousStatus'];
    }

    /**
     * @return string
     */
    protected function getTemplate(): string {
        $override = $this->getPayment()->getType()->paymentStatusChangedTemplate;

        return $override !== '' ? $override : 'qr-payments/emails/_statusChanged';
    }

    /**
     * @return string
     */
    protected function getTemplateMode(): string {
        $override = $this->getPayment()->getType()->paymentStatusChangedTemplate;

        return $override !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;
    }

    /**
     * @return array
     */
    protected function getTemplateVariables(): array {
        $payment = $this->getPayment();
        $statuses = Payment::statuses();

        return [
            'payment' => $payment,
            'previousStatusLabel' => $statuses[$this->previousStatus]['label'],
            'statusLabel' => $statuses[$payment->status]['label'],
        ];
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
        return Craft::t('qr-payments', 'Update on your payment {reference}', [
            'reference' => $this->getPayment()->remittanceReference,
        ]);
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
