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
 * Class SendSubscriptionActivatedEmailJob
 *
 * Queued by `SubscriptionsService::activate()` right after a successful
 * save, only on a real `requested`/`expired` → `active` transition (that
 * method's own `$wasActive` guard) — never on the harmless re-save of an
 * already-`active` subscription a renewal payment triggers.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
class SendSubscriptionActivatedEmailJob extends SendTemplatedEmailJob {
    public int $subscriptionId;

    /** The `statusHistory` entry (`Subscription::STATUS_ACTIVE`) this email was sent for — see `EmailLogService`. */
    public ?string $historyEntryId = null;

    private ?Subscription $subscription = null;

    /**
     * See `SendPaymentCreatedEmailJob::__sleep()` — same reason.
     *
     * @return string[]
     */
    public function __sleep(): array {
        return ['subscriptionId', 'historyEntryId', 'triggeredByUserId'];
    }

    /**
     * @return string
     */
    protected function getTemplate(): string {
        $override = $this->getSubscription()->getSubscriptionType()?->subscriptionActivatedTemplate ?? '';

        return $override !== '' ? $override : 'qr-payments/emails/_subscriptionActivated';
    }

    /**
     * @return string
     */
    protected function getTemplateMode(): string {
        $override = $this->getSubscription()->getSubscriptionType()?->subscriptionActivatedTemplate ?? '';

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
        return Craft::t('qr-payments', 'Your subscription is now active');
    }

    /**
     * @return array
     */
    protected function getLogContext(): array {
        return ['subscriptionId' => $this->subscriptionId, 'historyEntryId' => $this->historyEntryId];
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
