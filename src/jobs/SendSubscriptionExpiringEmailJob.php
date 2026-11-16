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
 * Class SendSubscriptionExpiringEmailJob
 *
 * The subscription-level heads-up — queued by `ReminderController::
 * queueSubscriptionExpiryNotices()` for every active subscription whose
 * coverage (`Subscription::getCoverageEnd()`) runs out in exactly 7 days,
 * same exact-day-match pattern `queuePaymentReminders()` already uses for
 * `Payment::dueDate`. Not tied to one logged status-history entry — the
 * coverage end it warns about is itself only ever derived, never stored.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
class SendSubscriptionExpiringEmailJob extends SendTemplatedEmailJob {
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
        $override = $this->getSubscription()->getSubscriptionType()?->subscriptionExpiringTemplate ?? '';

        return $override !== '' ? $override : 'qr-payments/emails/_subscriptionExpiring';
    }

    /**
     * @return string
     */
    protected function getTemplateMode(): string {
        $override = $this->getSubscription()->getSubscriptionType()?->subscriptionExpiringTemplate ?? '';

        return $override !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;
    }

    /**
     * @return array
     */
    protected function getTemplateVariables(): array {
        return ['subscription' => $this->getSubscription()];
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
        return Craft::t('qr-payments', 'Your subscription is expiring soon');
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
