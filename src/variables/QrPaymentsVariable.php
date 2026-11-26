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

namespace digitalastronaut\craftqrpayments\variables;

use Craft;
use craft\elements\User;

use Illuminate\Support\Collection;
use yii\base\Behavior;

use digitalastronaut\craftqrpayments\elements\db\PaymentQuery;
use digitalastronaut\craftqrpayments\elements\db\SubscriptionQuery;
use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

/**
 * Class QrPaymentsVariable
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class QrPaymentsVariable extends Behavior {
    /**
     * @return self
     */
    public function getQrPayments(): self {
        return $this;
    }

    /**
     * @param array $criteria
     * @return PaymentQuery
     */
    public function payments(array $criteria = []): PaymentQuery {
        $query = Payment::find();
        Craft::configure($query, $criteria);
        return $query;
    }

    /**
     * @param array $criteria
     * @return SubscriptionQuery
     */
    public function subscriptions(array $criteria = []): SubscriptionQuery {
        $query = Subscription::find();
        Craft::configure($query, $criteria);
        return $query;
    }

    /**
     * Every configured `PaymentType`, as a queryable `Collection` instead
     * of the plain array `PaymentTypesService::getAllPaymentTypes()`
     * returns — lets a template do `craft.qrPayments.paymentTypes()
     * .firstWhere('handle', 'x')`/`.whereIn('handle', [...])` etc.
     * without pulling in eligibility filtering the way
     * `getEligiblePaymentTypes()` does.
     *
     * @return Collection<int, PaymentType>
     */
    public function paymentTypes(): Collection {
        return Collection::make(QrPayments::getInstance()->getPaymentTypes()->getAllPaymentTypes());
    }

    /**
     * The `SubscriptionType` counterpart to `paymentTypes()` — see its
     * docblock.
     *
     * @return Collection<int, SubscriptionType>
     */
    public function subscriptionTypes(): Collection {
        return Collection::make(QrPayments::getInstance()->getSubscriptionTypes()->getAllSubscriptionTypes());
    }

    /**
     * Looks up a single `PaymentType` by its handle — the model-level
     * counterpart to `payments()`'s element query, for templates that
     * need the type itself (its fixed `amount`, `isRequestableBy()`,
     * etc.) rather than a list of its payments.
     *
     * @param string $handle
     * @return PaymentType|null
     */
    public function paymentType(string $handle): ?PaymentType {
        return QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeByHandle($handle);
    }

    /**
     * Looks up a single `SubscriptionType` by its handle — the
     * model-level counterpart to `subscriptions()`'s element query, for
     * templates that need the type itself (its `amount`, `interval`,
     * `isRequestableBy()`, etc.) rather than a list of its subscribers.
     *
     * @param string $handle
     * @return SubscriptionType|null
     */
    public function subscriptionType(string $handle): ?SubscriptionType {
        return QrPayments::getInstance()->getSubscriptionTypes()->getSubscriptionTypeByHandle($handle);
    }

    /**
     * `$user`'s own most recent `Subscription` of `$type`, whatever its
     * status — see `Subscription::latestFor()`. The entry point for
     * status questions, meant to be chained off directly rather than
     * asked as separate `(user, type)`-keyed questions:
     * `craft.qrPayments.subscription(user, type).getStatus()`,
     * `.validUntil()` — safe to call with no guard of your own either
     * way, since `latestFor()` never returns `null`. A template still
     * needs to check `.id` before trusting `.getStatus()` as
     * "outstanding" — see `latestFor()`'s own docblock.
     *
     * @param User|null $user
     * @param SubscriptionType $type
     * @return Subscription
     */
    public function subscription(?User $user, SubscriptionType $type): Subscription {
        return Subscription::latestFor($user, $type);
    }

    /**
     * Every `PaymentType` `$user` is allowed to request (see
     * `PaymentType::isRequestableBy()`) — lets a template resolve "which
     * of these specific types applies to this user" (when a site's
     * payment types aren't all interchangeable, e.g. several unrelated
     * plans alongside a handful of access-gated ones) in one call,
     * `|first`, instead of mapping handles to types and filtering itself.
     *
     * @param string[] $handles Restricts to just these, checked in this
     * order — the usual case, when a site's types aren't all
     * interchangeable. Empty (the default) considers every configured
     * `PaymentType`, ordered by name like `getAllPaymentTypes()`.
     * @param User|null $user Defaults to whoever's logged in (null for a guest) when omitted.
     * @return PaymentType[]
     */
    public function getEligiblePaymentTypes(array $handles = [], ?User $user = null): array {
        $user ??= Craft::$app->getUser()->getIdentity();

        if (empty($handles)) {
            return QrPayments::getInstance()->getPaymentTypes()->getRequestableTypesFor($user);
        }

        $types = [];

        foreach ($handles as $handle) {
            $type = $this->paymentType($handle);

            if ($type !== null && $type->isRequestableBy($user)) $types[] = $type;
        }

        return $types;
    }

    /**
     * The `SubscriptionType` counterpart to `getEligiblePaymentTypes()` —
     * see its docblock.
     *
     * @param string[] $handles Restricts to just these, checked in this
     * order. Empty (the default) considers every configured
     * `SubscriptionType`, ordered by name like `getAllSubscriptionTypes()`.
     * @param User|null $user Defaults to whoever's logged in (null for a guest) when omitted.
     * @return SubscriptionType[]
     */
    public function getEligibleSubscriptionTypes(array $handles = [], ?User $user = null): array {
        $user ??= Craft::$app->getUser()->getIdentity();

        if (empty($handles)) {
            return QrPayments::getInstance()->getSubscriptionTypes()->getRequestableTypesFor($user);
        }

        $types = [];

        foreach ($handles as $handle) {
            $type = $this->subscriptionType($handle);

            if ($type !== null && $type->isRequestableBy($user)) $types[] = $type;
        }

        return $types;
    }

    /**
     * A signed, short-lived token certifying a request (to
     * `qr-payments/payments/request`/`qr-payments/subscriptions/request`)
     * is for `$userId` and `$typeHandle` — see `RequestTokensService` for
     * the full rationale. Meant to be rendered straight into a hidden
     * `payer` form field:
     * `{{ hiddenInput("payer", craft.qrPayments.requestToken(artist.id, "artistLicense")) }}`
     *
     * @param int|null $userId The target user the request is for — null for a guest.
     * @param string $typeHandle The payment/subscription type handle the request is for.
     * @return string
     */
    public function requestToken(?int $userId, string $typeHandle): string {
        return QrPayments::getInstance()->getRequestTokens()->issue($userId, $typeHandle);
    }
}
