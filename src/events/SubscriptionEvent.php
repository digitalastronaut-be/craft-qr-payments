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

use digitalastronaut\craftqrpayments\elements\Subscription;

use yii\base\Event;

/**
 * Class SubscriptionEvent
 *
 * Fired by `SubscriptionsService` around status transitions, so site-level
 * code (not this plugin) can decide what an active/cancelled subscription
 * means for access control, e.g. assigning or removing a user group.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SubscriptionEvent extends Event {
    public Subscription $subscription;
}
