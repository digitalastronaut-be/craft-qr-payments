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

namespace digitalastronaut\craftqrpayments\events;

use craft\events\CancelableEvent;

use digitalastronaut\craftqrpayments\models\SubscriptionType;

/**
 * Class RequestSubscriptionEvent
 *
 * Fired by `SubscriptionsController::EVENT_BEFORE_REQUEST_SUBSCRIPTION`
 * before a subscription requested through `actionRequest()` is created —
 * same shape and purpose as `RequestPaymentEvent`, see its docblock.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class RequestSubscriptionEvent extends CancelableEvent {
    /** @var int|null The user this subscription is being requested for — null for a guest. */
    public ?int $userId = null;

    public string $email = '';

    public SubscriptionType $subscriptionType;

    /** @var string|null Shown via `asFailure()` when a listener sets `isValid = false`. */
    public ?string $error = null;
}
