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
use craft\helpers\DateTimeHelper;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\jobs\SendPaymentReminderEmailJob;
use digitalastronaut\craftqrpayments\jobs\SendPaymentsSummaryEmailJob;
use digitalastronaut\craftqrpayments\jobs\SendSubscriptionExpiredEmailJob;
use digitalastronaut\craftqrpayments\jobs\SendSubscriptionExpiringEmailJob;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\QrPayments;

use DateTime;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Class ReminderController
 *
 * One cron entry (`qr-payments/reminder/send`, run once daily — the
 * payer-facing reminder below only fires on an exact day match, so a less
 * frequent cron would miss payments) covers two distinct things:
 *
 * - The admin-facing summary digest: every `PaymentType` with its own
 *   recipients and at least one payment needing action gets its own
 *   queued `SendPaymentsSummaryEmailJob`.
 * - The payer-facing reminder: every payment, regardless of type, that's
 *   either exactly 7 days from its due date or exactly one day past it
 *   (the first day it's expired) gets its own queued
 *   `SendPaymentReminderEmailJob`, sent to the payer themself.
 * - The subscriber-facing expiry notices: the same exact-day-match idea,
 *   applied to `Subscription::getCoverageEnd()` instead of a payment's
 *   `dueDate` — see `queueSubscriptionExpiryNotices()`.
 *
 * Devs only ever need the one cron line either way.
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
        $summariesQueued = $this->queuePaymentsSummaries();
        $remindersQueued = $this->queuePaymentReminders();
        $subscriptionNoticesQueued = $this->queueSubscriptionExpiryNotices();

        if ($summariesQueued === 0 && $remindersQueued === 0 && $subscriptionNoticesQueued === 0) {
            $this->stdout("Nothing to send\n");

            return ExitCode::OK;
        }

        Craft::$app->queue->run();

        $this->stdout("Sent summaries for {$summariesQueued} payment type(s), {$remindersQueued} payment reminder(s) and {$subscriptionNoticesQueued} subscription notice(s)\n");

        return ExitCode::OK;
    }

    /**
     * @return int Number of summary jobs queued.
     */
    private function queuePaymentsSummaries(): int {
        $queued = 0;

        foreach (QrPayments::getInstance()->getPaymentTypes()->getAllPaymentTypes() as $type) {
            $recipientEmails = $this->recipientEmailsFor($type);

            if (!$recipientEmails) continue;

            $hasPaymentsNeedingAction = Payment::find()
                ->typeId($type->id)
                ->status([Payment::STATUS_REQUESTED, Payment::STATUS_AWAITING_CONFIRMATION, Payment::STATUS_EXPIRED, Payment::STATUS_PARTIAL])
                ->exists();

            if (!$hasPaymentsNeedingAction) continue;

            Craft::$app->queue->push(new SendPaymentsSummaryEmailJob([
                'paymentTypeId' => $type->id,
                'recipientEmails' => $recipientEmails,
            ]));

            $this->stdout("Queued summary for \"{$type->name}\"\n");

            $queued++;
        }

        return $queued;
    }

    /**
     * Queues the payer-facing reminder for every payment that's either
     * exactly 7 days from its due date, or exactly one day past it (the
     * first day it's expired) — an exact day match, not a range, so each
     * payment is only ever matched on one single day's run.
     *
     * @return int Number of reminder jobs queued.
     */
    private function queuePaymentReminders(): int {
        $now = DateTimeHelper::currentUTCDateTime();

        $dueSoon = Payment::find()
            ->status(Payment::STATUS_REQUESTED)
            ->dueDateBetween(...$this->dayBounds((clone $now)->modify('+7 days')))
            ->all();

        $justExpired = Payment::find()
            ->status(Payment::STATUS_EXPIRED)
            ->dueDateBetween(...$this->dayBounds((clone $now)->modify('-1 day')))
            ->all();

        $queued = 0;

        foreach ([...$dueSoon, ...$justExpired] as $payment) {
            if (!$payment->email) continue;

            Craft::$app->queue->push(new SendPaymentReminderEmailJob(['paymentId' => $payment->id]));

            $queued++;
        }

        return $queued;
    }

    /**
     * Same exact-day-match idea as `queuePaymentReminders()`, applied to
     * `Subscription::getCoverageEnd()` instead of a payment's `dueDate`.
     * Filtered in PHP against every subscription with `status(null)`,
     * same reasoning (and same cron-only performance tradeoff)
     * `SubscriptionsService::getDueSubscriptions()`'s own docblock lays
     * out: `.status(STATUS_ACTIVE)` would already (correctly, for the CP)
     * exclude a subscription whose coverage just ran out — exactly the
     * "just expired" half below needs to still see.
     *
     * @return int Number of notice jobs queued.
     */
    private function queueSubscriptionExpiryNotices(): int {
        $now = DateTimeHelper::currentUTCDateTime();
        $expiringDay = (clone $now)->modify('+7 days')->format('Y-m-d');
        $justExpiredDay = (clone $now)->modify('-1 day')->format('Y-m-d');

        $queued = 0;

        foreach (Subscription::find()->status(null)->all() as $subscription) {
            if (!$subscription->getNotificationEmail()) continue;

            $coverageEnd = $subscription->getCoverageEnd();
            if ($coverageEnd === null) continue;

            $coverageEndDay = $coverageEnd->format('Y-m-d');

            if ($subscription->status === Subscription::STATUS_ACTIVE && $coverageEndDay === $expiringDay) {
                Craft::$app->queue->push(new SendSubscriptionExpiringEmailJob(['subscriptionId' => $subscription->id]));

                $queued++;
            } elseif ($subscription->getStatus() === Subscription::STATUS_EXPIRED && $coverageEndDay === $justExpiredDay) {
                Craft::$app->queue->push(new SendSubscriptionExpiredEmailJob(['subscriptionId' => $subscription->id]));

                $queued++;
            }
        }

        return $queued;
    }

    /**
     * @param DateTime $day
     * @return string[] [start-of-day, end-of-day], formatted for `PaymentQuery::dueDateBetween()`.
     */
    private function dayBounds(DateTime $day): array {
        $start = (clone $day)->setTime(0, 0, 0);
        $end = (clone $day)->setTime(23, 59, 59);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * @param PaymentType $type
     * @return string[]
     */
    private function recipientEmailsFor(PaymentType $type): array {
        if (!$type->reminderRecipients) return [];

        $recipientEmails = [];

        foreach (User::find()->id($type->reminderRecipients)->status(null)->all() as $recipient) {
            if ($recipient->email) $recipientEmails[] = $recipient->email;
        }

        return $recipientEmails;
    }
}
