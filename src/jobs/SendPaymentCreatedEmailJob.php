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
 * Class SendPaymentCreatedEmailJob
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class SendPaymentCreatedEmailJob extends SendTemplatedEmailJob {
    public int $paymentId;

    /** The `statusHistory` entry (`Payment::HISTORY_TYPE_CREATED`) this email was sent for — see `EmailLogService`. */
    public ?string $historyEntryId = null;

    private ?Payment $payment = null;

    /**
     * `Queue::push()` calls `getDescription()` (base class's
     * `defaultDescription()`, via `getTemplate()`) before the job is
     * serialized, which lazily populates `$payment` purely to build that
     * description string. Left unguarded, that cached `Payment` — and its
     * own cached, closure-bearing custom field layout (e.g. a Matrix or
     * CKEditor field's `NestedElementManager`) — would ride along into
     * `serialize()`, which doesn't allow closures. Excluding it here keeps
     * the "entities are re-fetched on execute()" contract from the class
     * docblock true in practice.
     *
     * @return string[]
     */
    public function __sleep(): array {
        return ['paymentId', 'historyEntryId', 'triggeredByUserId'];
    }

    /**
     * @return array
     */
    protected function getLogContext(): array {
        return ['paymentId' => $this->paymentId, 'historyEntryId' => $this->historyEntryId];
    }

    /**
     * @return string
     */
    protected function getTemplate(): string {
        $override = $this->getPayment()->getType()->paymentCreatedTemplate;

        return $override !== '' ? $override : 'qr-payments/emails/_paymentCreated';
    }

    /**
     * @return string
     */
    protected function getTemplateMode(): string {
        $override = $this->getPayment()->getType()->paymentCreatedTemplate;

        return $override !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;
    }

    /**
     * @return array
     */
    protected function getTemplateVariables(): array {
        return ['payment' => $this->getPayment()];
    }

    /**
     * @return array<string, array{content: string, options?: array}>
     */
    protected function getEmbeddedContent(): array {
        $payment = $this->getPayment();

        if (!$payment->qrCode) return [];

        return [
            'qrContentId' => [
                'content' => $payment->qrCode,
                'options' => ['fileName' => 'payment-qr.png', 'contentType' => 'image/png'],
            ],
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
        return Craft::t('qr-payments', 'Your payment request');
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
