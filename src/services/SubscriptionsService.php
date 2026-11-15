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
use craft\helpers\DateTimeHelper;
use craft\helpers\MoneyHelper;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\events\SubscriptionEvent;
use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

use Carbon\Carbon;
use DateTime;
use InvalidArgumentException;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Class SubscriptionsService
 *
 * CRUD and lookups now go through `Subscription::find()` / `Craft::$app
 * ->getElements()->saveElement()` directly — `Subscription` is a real
 * element, not a plain model, so this service only holds the domain
 * operations that are more than plain persistence: status transitions
 * (firing events) and `advance()`.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SubscriptionsService extends Component {
    public const string EVENT_AFTER_ACTIVATE_SUBSCRIPTION = 'afterActivateSubscription';
    public const string EVENT_AFTER_CANCEL_SUBSCRIPTION = 'afterCancelSubscription';

    /**
     * Every `active` subscription whose `nextDueDate` has arrived — what
     * `SubscriptionController::actionAdvance()` runs to decide what to
     * advance.
     *
     * @return Subscription[]
     */
    public function getDueSubscriptions(): array {
        return Subscription::find()
            ->status(Subscription::STATUS_ACTIVE)
            ->nextDueDateBefore(DateTimeHelper::currentUTCDateTime()->format(DateTime::ATOM))
            ->all();
    }

    /**
     * @param Subscription $subscription
     * @return bool False if the subscription failed to save.
     */
    public function activate(Subscription $subscription): bool {
        $subscription->status = Subscription::STATUS_ACTIVE;

        if (!Craft::$app->getElements()->saveElement($subscription)) return false;

        if ($this->hasEventHandlers(self::EVENT_AFTER_ACTIVATE_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_AFTER_ACTIVATE_SUBSCRIPTION, new SubscriptionEvent(['subscription' => $subscription]));
        }

        return true;
    }

    /**
     * @param Subscription $subscription
     * @return bool False if the subscription failed to save.
     */
    public function pause(Subscription $subscription): bool {
        $subscription->status = Subscription::STATUS_PAUSED;

        return Craft::$app->getElements()->saveElement($subscription);
    }

    /**
     * @param Subscription $subscription
     * @return bool False if the subscription failed to save.
     */
    public function cancel(Subscription $subscription): bool {
        $subscription->status = Subscription::STATUS_CANCELLED;
        $subscription->endedAt = DateTimeHelper::currentUTCDateTime();

        if (!Craft::$app->getElements()->saveElement($subscription)) return false;

        if ($this->hasEventHandlers(self::EVENT_AFTER_CANCEL_SUBSCRIPTION)) {
            $this->trigger(self::EVENT_AFTER_CANCEL_SUBSCRIPTION, new SubscriptionEvent(['subscription' => $subscription]));
        }

        return true;
    }

    /**
     * Stamps out this subscription's next `Payment` (due on its current
     * `nextDueDate`, or today if that's never been set) and pushes
     * `nextDueDate` forward by one `interval`. No gateway integration,
     * same as a one-off payment: this just creates the next payment for
     * manual reconciliation.
     *
     * @param Subscription $subscription
     * @return Payment
     * @throws InvalidConfigException if `subscriptionTypeId` doesn't resolve to a real `SubscriptionType`, or its `paymentTypeId` doesn't resolve to a real `PaymentType`
     * @throws InvalidArgumentException if the beneficiary/IBAN/BIC settings don't produce a valid EPC payload, or if the effective interval isn't one of `SubscriptionType::INTERVAL_*`
     * @throws Exception if the generated payment fails validation on save
     */
    public function advance(Subscription $subscription): Payment {
        $type = $subscription->getPaymentType();

        if ($type === null) {
            throw new InvalidConfigException("Subscription #{$subscription->id} (subscription type #{$subscription->subscriptionTypeId}) has no resolvable payment type.");
        }

        $dueDate = $subscription->nextDueDate ?? DateTimeHelper::currentUTCDateTime();

        $payment = QrPayments::getInstance()->getPayments()->createPayment(
            type: $type,
            amount: (float) MoneyHelper::toDecimal($subscription->getEffectiveAmount()),
            email: $subscription->getNotificationEmail(),
            dueDate: $dueDate,
            subscriptionId: $subscription->id,
            userId: $subscription->userId,
        );

        $subscription->nextDueDate = $this->nextDueDateAfter($dueDate, $subscription->getEffectiveInterval());
        Craft::$app->getElements()->saveElement($subscription);

        return $payment;
    }

    /**
     * @param DateTime $from
     * @param string $interval One of `SubscriptionType::INTERVAL_*`
     * @return DateTime
     * @throws InvalidArgumentException if `$interval` isn't a recognized value
     */
    private function nextDueDateAfter(DateTime $from, string $interval): DateTime {
        $next = Carbon::instance($from);

        return match ($interval) {
            // "no overflow" so e.g. Jan 31 + 1 month lands on the last day
            // of February instead of rolling over into March.
            SubscriptionType::INTERVAL_DAILY => $next->addDay(),
            SubscriptionType::INTERVAL_WEEKLY => $next->addWeek(),
            SubscriptionType::INTERVAL_BIWEEKLY => $next->addWeeks(2),
            SubscriptionType::INTERVAL_MONTHLY => $next->addMonthNoOverflow(),
            SubscriptionType::INTERVAL_QUARTERLY => $next->addQuarterNoOverflow(),
            SubscriptionType::INTERVAL_YEARLY => $next->addYear(),
            default => throw new InvalidArgumentException("Invalid subscription interval: {$interval}"),
        };
    }
}
