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

use digitalastronaut\craftqrpayments\elements\Subscription;

use RuntimeException;

/**
 * Class SendSubscriptionExpiredEmailJob
 *
 * Queued by `ReminderController::queueSubscriptionExpiryNotices()` for
 * every subscription whose coverage ran out exactly 1 day ago with
 * nothing paid since — the `justExpired` half of the same day-match
 * pattern `queuePaymentReminders()` uses for payments. Same caveat as
 * `SendSubscriptionExpiringEmailJob`: no `historyEntryId`, since the
 * virtual `expired` status it's warning about is never itself logged.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
class SendSubscriptionExpiredEmailJob extends SendTemplatedEmailJob {
    public int $subscriptionId;

    private ?Subscription $subscription = null;

    /**
     * See `SendPaymentCreatedEmailJob::__sleep()` — same reason.
     *
     * @return string[]
     */
    public function __sleep(): array {
        return ['subscriptionId', 'triggeredByUserId'];
    }

    /**
     * @return string
     */
    protected function getTemplate(): string {
        $override = $this->getSubscription()->getSubscriptionType()?->subscriptionExpiredTemplate ?? '';

        return $override !== '' ? $override : 'qr-payments/emails/_subscriptionExpired';
    }

    /**
     * @return string
     */
    protected function getTemplateMode(): string {
        $override = $this->getSubscription()->getSubscriptionType()?->subscriptionExpiredTemplate ?? '';

        return $override !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;
    }

    /**
     * @return array
     */
    protected function getTemplateVariables(): array {
        $subscription = $this->getSubscription();

        return [
            'subscription' => $subscription,
            'paymentUrl' => $subscription->getLatestPayment()?->getUrl(),
        ];
    }

    /**
     * @return string|null
     */
    protected function getRecipient(): ?string {
        return $this->getSubscription()->getNotificationEmail() ?: null;
    }

    /**
     * @return string
     */
    protected function getSubject(): string {
        return Craft::t('qr-payments', 'Your subscription has expired');
    }

    /**
     * @return array
     */
    protected function getLogContext(): array {
        return ['subscriptionId' => $this->subscriptionId];
    }

    /**
     * @return Subscription
     */
    private function getSubscription(): Subscription {
        if ($this->subscription !== null) return $this->subscription;

        $subscription = Subscription::find()->id($this->subscriptionId)->status(null)->one();

        if (!$subscription) throw new RuntimeException("No subscription found with id {$this->subscriptionId}.");

        return $this->subscription = $subscription;
    }
}
