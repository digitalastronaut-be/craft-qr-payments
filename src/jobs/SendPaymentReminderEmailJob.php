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
 * Class SendPaymentReminderEmailJob
 *
 * Queued once per `ReminderController::actionSend()` run, carrying every
 * recipient's email address. The digest content is the same for everyone,
 * so the template is rendered once here and sent individually to each
 * recipient — payments needing action are looked up fresh at execute()
 * time, the same way the "Needs action" element source does.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SendPaymentReminderEmailJob extends BaseJob {
    /** @var string[] */
    public array $recipientEmails = [];

    /**
     * @param QueueInterface $queue
     * @return void
     */
    public function execute($queue): void {
        $payments = Payment::find()
            // Overpaid isn't included here — that's money we owe the
            // customer back, not a balance still owed to us.
            ->status([Payment::STATUS_PENDING, Payment::STATUS_EXPIRED, Payment::STATUS_PARTIAL])
            ->orderBy(['dueDate' => SORT_ASC])
            ->all();

        if (!$payments || !$this->recipientEmails) return;

        $override = QrPayments::getInstance()->getSettings()->paymentReminderTemplate;
        $template = $override !== '' ? $override : 'qr-payments/emails/_paymentReminder';
        $mode = $override !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;

        $html = Craft::$app->getView()->renderTemplate($template, ['payments' => $payments], $mode);

        $subject = Craft::t('qr-payments', '{count} payment(s) still need to be processed', [
            'count' => count($payments),
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
        return Craft::t('qr-payments', 'Sending payment reminder emails');
    }
}
