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
use digitalastronaut\craftqrpayments\QrPayments;

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

    private ?Payment $payment = null;

    /**
     * @return string
     */
    protected function getTemplate(): string {
        $override = QrPayments::getInstance()->getSettings()->paymentCreatedTemplate;

        return $override !== '' ? $override : 'qr-payments/emails/_paymentCreated';
    }

    /**
     * @return string
     */
    protected function getTemplateMode(): string {
        $override = QrPayments::getInstance()->getSettings()->paymentCreatedTemplate;

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
        return $this->getPayment()->email ?: null;
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
