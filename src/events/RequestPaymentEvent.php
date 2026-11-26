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

use digitalastronaut\craftqrpayments\models\PaymentType;

/**
 * Class RequestPaymentEvent
 *
 * Fired by `PaymentsController::EVENT_BEFORE_REQUEST_PAYMENT` before a
 * payment requested through `actionRequest()` is created, so site-level
 * code (not this plugin) can enforce its own eligibility rules — who's
 * allowed to request this type, for this user, at all — without a
 * parallel controller. Set `isValid = false` (inherited from
 * `CancelableEvent`) and `$error` to reject the request; `actionRequest()`
 * returns that as an `asFailure()` message.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class RequestPaymentEvent extends CancelableEvent {
    /** @var int|null The user this payment is being requested for — null for a guest. */
    public ?int $userId = null;

    public string $email = '';

    public PaymentType $paymentType;

    /** @var string|null Shown via `asFailure()` when a listener sets `isValid = false`. */
    public ?string $error = null;
}
