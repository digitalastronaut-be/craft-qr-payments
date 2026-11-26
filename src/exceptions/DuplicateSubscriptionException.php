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

namespace digitalastronaut\craftqrpayments\exceptions;

use yii\base\Exception;

/**
 * Class DuplicateSubscriptionException
 *
 * Thrown by `SubscriptionsService::requestSubscription()` when the
 * identified subscriber already has a `requested`/`active` `Subscription`
 * of the same `SubscriptionType` (see `SubscriptionsService::
 * hasOutstandingSubscription()`) — a dedicated subtype of the plain
 * `Exception` that method otherwise throws on a real save failure, so a
 * caller like `SubscriptionsController::actionRequest()` can show this
 * specific, expected-to-happen case as a clean message instead of a
 * generic one.
 *
 * `Subscription::validateNotDuplicate()` enforces the same rule at the
 * element-validation level, as a backstop for any other save path (the
 * CP's native "New subscription" draft flow, a future import) — this
 * exception only covers the one call site that benefits from a
 * dedicated message ahead of that generic validation failure.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class DuplicateSubscriptionException extends Exception {}
