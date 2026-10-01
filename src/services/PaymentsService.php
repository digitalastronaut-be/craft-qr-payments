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

namespace digitalastronaut\craftqrpayments\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\MoneyHelper;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\jobs\SendPaymentCreatedEmailJob;
use digitalastronaut\craftqrpayments\QrPayments;

use DateTime;
use InvalidArgumentException;
use yii\base\Exception;

/**
 * Class PaymentsService
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class PaymentsService extends Component {
    /**
     * @param float $amount
     * @param string $email
     * @param DateTime|null $dueDate
     * @return Payment
     * @throws InvalidArgumentException if the beneficiary/IBAN/BIC settings don't produce a valid EPC payload
     * @throws Exception if the payment fails validation on save
     */
    public function createPayment(float $amount, string $email, ?DateTime $dueDate = null): Payment {
        $settings = QrPayments::getInstance()->getSettings();

        $payment = new Payment();
        $payment->email = $email;
        $payment->beneficiaryName = $settings->getBeneficiaryName();
        $payment->iban = $settings->getIban();
        $payment->bic = $settings->getBic();
        $payment->amount = $amount;
        $payment->dueDate = $dueDate ?? DateTimeHelper::currentUTCDateTime()->modify("+{$settings->defaultDueDateDays} days");
        $payment->remittanceReference = $this->generateUniqueReference();
        $payment->title = $payment->remittanceReference;

        $qrCode = QrPayments::getInstance()->getEpcQr()->generateQrCode(
            beneficiaryName: $payment->beneficiaryName,
            iban: $payment->iban,
            amount: (float) MoneyHelper::toDecimal($payment->getAmount()),
            bic: $payment->bic,
            remittanceReference: $payment->remittanceReference,
        );

        $payment->qrCode = $qrCode->getString();
        $payment->setFieldValuesFromRequest('fields');

        if (!Craft::$app->getElements()->saveElement($payment)) {
            throw new Exception(sprintf(
                'Failed to create payment: %s',
                json_encode($payment->getErrors())
            ));
        }

        Craft::$app->getQueue()->push(new SendPaymentCreatedEmailJob(['paymentId' => $payment->id]));
        
        App::maxPowerCaptain();
        Craft::$app->queue->run();

        return $payment;
    }

    /**
     * @return string
     */
    private function generateUniqueReference(): string {
        $qrPayments = QrPayments::getInstance();
        $prefix = $qrPayments->getSettings()->referencePrefix;

        do {
            $reference = $qrPayments->getEpcQr()->generateRfReference($this->referenceBody($prefix));
        } while (Payment::find()->remittanceReference($reference)->exists());

        return $reference;
    }

    /**
     * @param string $prefix
     * @return string
     */
    private function referenceBody(string $prefix): string {
        $prefix = substr(strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix)), 0, 12);
        $random = strtoupper(bin2hex(random_bytes(4)));

        return substr($prefix . $random, 0, 21);
    }
}
