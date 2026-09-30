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

namespace digitalastronaut\craftqrpayments\console\controllers;

use Craft;
use craft\elements\User;

use digitalastronaut\craftqrpayments\jobs\SendPaymentReminderEmailJob;
use digitalastronaut\craftqrpayments\QrPayments;

use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Class ReminderController
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class ReminderController extends Controller {
    public $defaultAction = 'send';

    /**
     * @return int
     */
    public function actionSend(): int {
        $settings = QrPayments::getInstance()->getSettings();
        $recipientEmails = [];

        $recipients = $settings->reminderRecipients
            ? User::find()->id($settings->reminderRecipients)
                ->status(null)->all() : [];

        foreach ($recipients as $recipient) {
            if ($recipient->email) $recipientEmails[] = $recipient->email;
        }

        if (!$recipientEmails) {
            $this->stdout("No recipients configured");
            return ExitCode::OK;
        }

        Craft::$app->queue->push(new SendPaymentReminderEmailJob(['recipientEmails' => $recipientEmails]));
        Craft::$app->queue->run();

        $this->stdout("Payment reminders queued");

        return ExitCode::OK;
    }
}
