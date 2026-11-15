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
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

use InvalidArgumentException;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Class SeedController
 *
 * Local-dev fixture generator: `ddev craft qr-payments/seed` fills the site
 * with a realistic, varied set of payment types, subscription types,
 * subscriptions (at every status, with a believable payment history behind
 * each one) and one-off payments (one per `Payment::STATUS_*`) — enough to
 * see the plugin's CP screens, status badges, emails (Mailpit catches
 * every one, since each payment created/status-changed fires for real —
 * see `Payment::afterSave()`) and the new subscription-payments table all
 * actually populated, instead of staring at empty element indexes.
 *
 * `--subscriptions=N` and `--standalonePayments=N` (see `options()`) control
 * the volume — defaults are high enough (120 subscriptions across the 3
 * seed subscription types, each with its own multi-cycle payment history,
 * plus 200 standalone payments) to put real load on the database rather
 * than a handful of token rows.
 *
 * Safe to rerun any number of times, at any volume: every payment type,
 * subscription type, subscription and payment this command has ever
 * created is tagged with a fixed, hardcoded `seed*` handle or a
 * `@seed.test` email address, and `actionIndex()` always wipes exactly
 * that — nothing else — before deterministically regenerating it from
 * `$subscriptions`/`$standalonePayments` rows of procedurally-varied data
 * (see `generatedSubscriber()`/`generatedStandalonePayment()`). So it
 * always leaves a predictable-*shape* dataset behind sized exactly to
 * whatever you asked for, and never touches real content.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class SeedController extends Controller {
    public $defaultAction = 'index';

    /**
     * @var int Number of `Subscription` elements to seed, spread evenly across the 3 seed subscription types.
     */
    public int $subscriptions = 120;

    /**
     * @var int Number of standalone (not tied to any subscription) `Payment` elements to seed.
     */
    public int $standalonePayments = 200;

    /**
     * @inheritdoc
     */
    public function options($actionID): array {
        return array_merge(parent::options($actionID), ['subscriptions', 'standalonePayments']);
    }

    /**
     * @var array<string, array{name: string, beneficiaryName: string, iban: string, referencePrefix: string, defaultDueDateDays: int}>
     */
    private const array PAYMENT_TYPES = [
        'seedBroodabonnement' => [
            'name' => 'Broodabonnement',
            'beneficiaryName' => 'Hoeve Bakkerij',
            'iban' => 'BE68539007547034',
            'referencePrefix' => 'BREAD',
            'defaultDueDateDays' => 10,
        ],
        'seedWorkshops' => [
            'name' => 'Workshops',
            'beneficiaryName' => 'Hoeve Bakkerij',
            'iban' => 'NL91ABNA0417164300',
            'referencePrefix' => 'WORK',
            'defaultDueDateDays' => 7,
        ],
        'seedWebshop' => [
            'name' => 'Webshop bestellingen',
            'beneficiaryName' => 'Hoeve Bakkerij',
            'iban' => 'GB33BUKB20201555555555',
            'referencePrefix' => 'SHOP',
            'defaultDueDateDays' => 5,
        ],
    ];

    /**
     * @var array<string, array{name: string, paymentType: string, amount: float, interval: string}>
     */
    private const array SUBSCRIPTION_TYPES = [
        'seedWekelijksBrood' => [
            'name' => 'Wekelijks broodabonnement',
            'paymentType' => 'seedBroodabonnement',
            'amount' => 9.50,
            'interval' => SubscriptionType::INTERVAL_WEEKLY,
        ],
        'seedMaandelijksTaart' => [
            'name' => 'Maandelijkse taartbox',
            'paymentType' => 'seedBroodabonnement',
            'amount' => 28.00,
            'interval' => SubscriptionType::INTERVAL_MONTHLY,
        ],
        'seedKwartaalWorkshop' => [
            'name' => 'Kwartaalpas workshops',
            'paymentType' => 'seedWorkshops',
            'amount' => 65.00,
            'interval' => SubscriptionType::INTERVAL_QUARTERLY,
        ],
    ];

    /**
     * Rough day-length used only to pick a believable `startedAt` below —
     * not the real cadence math (`SubscriptionsService::nextDueDateAfter()`
     * uses Carbon's calendar-aware `addMonthNoOverflow()`/etc. for that).
     *
     * @var array<string, int>
     */
    private const array INTERVAL_DAYS = [
        SubscriptionType::INTERVAL_DAILY => 1,
        SubscriptionType::INTERVAL_WEEKLY => 7,
        SubscriptionType::INTERVAL_BIWEEKLY => 14,
        SubscriptionType::INTERVAL_MONTHLY => 30,
        SubscriptionType::INTERVAL_QUARTERLY => 91,
        SubscriptionType::INTERVAL_YEARLY => 365,
    ];

    /**
     * Combined with `LAST_NAMES` to procedurally generate as many distinct
     * `name`/`email` pairs as needed — see `generatedPerson()`.
     *
     * @var string[]
     */
    private const array FIRST_NAMES = [
        'Anne', 'Jan', 'Sophie', 'Thomas', 'Emma', 'Lucas', 'Marie', 'Noah',
        'Klara', 'Peter', 'Julie', 'Dries', 'Hannah', 'Bram', 'Lotte', 'Kevin',
        'Sarah', 'Tom', 'Lisa', 'Wout',
    ];

    /**
     * @var string[]
     */
    private const array LAST_NAMES = [
        'De Smet', 'Peeters', 'Willems', 'Janssens', 'Maes', 'Vermeulen',
        'Claes', 'Pauwels', 'De Ridder', 'Van Damme', 'Lemmens', 'Cools',
        'Goossens', 'Wouters', 'Hermans', 'De Backer', 'Mertens', 'Dubois',
        'Van den Berghe', 'Verhoeven',
    ];

    /**
     * One-off payments not tied to any subscription cycle through these —
     * one of each so every status badge/filter has something in it once
     * `$standalonePayments` is at least 8. `expired` gets a past due date
     * (see `generatedStandalonePayment()`); the rest get a future one.
     *
     * @var string[]
     */
    private const array STANDALONE_OUTCOMES = [
        'pending', 'expired', 'awaiting_confirmation', 'partial', 'paid', 'overpaid', 'cancelled', 'refunded',
    ];

    /**
     * @return int
     */
    public function actionIndex(): int {
        $this->stdout("Wiping previously seeded data...\n");
        $this->wipeSeedData();

        $this->stdout("Seeding payment types...\n");
        $paymentTypes = $this->seedPaymentTypes();

        $this->stdout("Seeding subscription types...\n");
        $subscriptionTypes = $this->seedSubscriptionTypes($paymentTypes);

        $userId = User::find()->admin(true)->one()?->id;

        $this->stdout("Seeding {$this->subscriptions} subscription(s) and their payment history...\n");
        $paymentCount = $this->seedSubscriptions($subscriptionTypes, $userId);

        $this->stdout("Seeding {$this->standalonePayments} standalone payment(s)...\n");
        $paymentCount += $this->seedStandalonePayments($paymentTypes, $userId);

        $this->stdout(sprintf(
            "Done: %d payment type(s), %d subscription type(s), %d subscription(s), %d payment(s).\n",
            count($paymentTypes),
            count($subscriptionTypes),
            $this->subscriptions,
            $paymentCount,
        ));

        return ExitCode::OK;
    }

    /**
     * Deletes every payment/subscription belonging to one of our seed
     * types (by `typeId`/`subscriptionTypeId`, so this catches seeded
     * payments whether they're standalone or subscription-stamped), then
     * the seed types themselves — in that order, since
     * `SubscriptionTypesService::deleteSubscriptionType()`/
     * `PaymentTypesService::deletePaymentType()` both refuse to delete a
     * type that's still referenced.
     *
     * @return void
     */
    private function wipeSeedData(): void {
        $elements = Craft::$app->getElements();

        foreach (array_keys(self::PAYMENT_TYPES) as $handle) {
            $type = QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeByHandle($handle);

            if ($type === null) continue;

            foreach (Payment::find()->typeId($type->id)->status(null)->all() as $payment) {
                $elements->deleteElement($payment, true);
            }
        }

        foreach (array_keys(self::SUBSCRIPTION_TYPES) as $handle) {
            $type = QrPayments::getInstance()->getSubscriptionTypes()->getSubscriptionTypeByHandle($handle);

            if ($type === null) continue;

            foreach (Subscription::find()->subscriptionTypeId($type->id)->status(null)->all() as $subscription) {
                $elements->deleteElement($subscription, true);
            }

            QrPayments::getInstance()->getSubscriptionTypes()->deleteSubscriptionType($type);
        }

        foreach (array_keys(self::PAYMENT_TYPES) as $handle) {
            $type = QrPayments::getInstance()->getPaymentTypes()->getPaymentTypeByHandle($handle);

            if ($type !== null) QrPayments::getInstance()->getPaymentTypes()->deletePaymentType($type);
        }
    }

    /**
     * @return array<string, PaymentType> Handle => the saved type.
     */
    private function seedPaymentTypes(): array {
        $types = [];

        foreach (self::PAYMENT_TYPES as $handle => $spec) {
            $type = new PaymentType([
                'name' => $spec['name'],
                'handle' => $handle,
                'beneficiaryName' => $spec['beneficiaryName'],
                'iban' => $spec['iban'],
                'referencePrefix' => $spec['referencePrefix'],
                'defaultDueDateDays' => $spec['defaultDueDateDays'],
            ]);

            if (!QrPayments::getInstance()->getPaymentTypes()->savePaymentType($type)) {
                throw new InvalidArgumentException("Failed to save payment type \"{$handle}\": " . json_encode($type->getErrors()));
            }

            $types[$handle] = $type;
        }

        return $types;
    }

    /**
     * @param array<string, PaymentType> $paymentTypes Handle => type, from `seedPaymentTypes()`.
     * @return array<string, SubscriptionType> Handle => the saved type.
     */
    private function seedSubscriptionTypes(array $paymentTypes): array {
        $types = [];

        foreach (self::SUBSCRIPTION_TYPES as $handle => $spec) {
            $type = new SubscriptionType([
                'name' => $spec['name'],
                'handle' => $handle,
                'paymentTypeId' => $paymentTypes[$spec['paymentType']]->id,
                'amount' => $spec['amount'],
                'interval' => $spec['interval'],
            ]);

            if (!QrPayments::getInstance()->getSubscriptionTypes()->saveSubscriptionType($type)) {
                throw new InvalidArgumentException("Failed to save subscription type \"{$handle}\": " . json_encode($type->getErrors()));
            }

            $types[$handle] = $type;
        }

        return $types;
    }

    /**
     * @param array<string, SubscriptionType> $subscriptionTypes Handle => type, from `seedSubscriptionTypes()`.
     * @param int|null $userId Attributed as the "operator" on every simulated payment entry.
     * @return int Total number of payments created across every subscriber.
     */
    private function seedSubscriptions(array $subscriptionTypes, ?int $userId): int {
        $paymentCount = 0;

        // The last cycle's due date lands on `$referenceNow`, not the real
        // "now" — a day's buffer so the still-pending payment it leaves
        // behind reads as comfortably upcoming regardless of what time of
        // day this command happens to run, rather than flickering between
        // "pending" and "expired" (`Payment::getStatus()` compares against
        // the real current moment) depending on the clock.
        $referenceNow = DateTimeHelper::currentUTCDateTime()->modify('+1 day');

        for ($index = 0; $index < $this->subscriptions; $index++) {
            $spec = $this->generatedSubscriber($index, $subscriptionTypes);

            $intervalDays = self::INTERVAL_DAYS[$subscriptionTypes[$spec['subscriptionType']]->interval];
            $startedAt = (clone $referenceNow)->modify('-' . ($intervalDays * ($spec['cycles'] - 1)) . ' days');

            $subscription = new Subscription([
                'email' => $spec['email'],
                'subscriptionTypeId' => $subscriptionTypes[$spec['subscriptionType']]->id,
                'startedAt' => $startedAt,
                'nextDueDate' => $startedAt,
            ]);

            if (!Craft::$app->getElements()->saveElement($subscription)) {
                throw new InvalidArgumentException("Failed to save subscription for \"{$spec['email']}\": " . json_encode($subscription->getErrors()));
            }

            $payments = [];

            for ($i = 0; $i < $spec['cycles']; $i++) {
                $payments[] = QrPayments::getInstance()->getSubscriptions()->advance($subscription);
            }

            foreach (array_slice($payments, 0, $spec['paidCount']) as $payment) {
                $this->applyOutcome($payment, 'paid', $userId);
            }

            $paymentCount += count($payments);

            if ($spec['finalStatus'] === Subscription::STATUS_PAUSED) {
                QrPayments::getInstance()->getSubscriptions()->pause($subscription);
            } elseif ($spec['finalStatus'] === Subscription::STATUS_CANCELLED) {
                QrPayments::getInstance()->getSubscriptions()->cancel($subscription);
            }

            $this->reportProgress($index + 1, $this->subscriptions);
        }

        return $paymentCount;
    }

    /**
     * @param array<string, PaymentType> $paymentTypes Handle => type, from `seedPaymentTypes()`.
     * @param int|null $userId Attributed as the "operator" on every simulated payment entry.
     * @return int Number of payments created.
     */
    private function seedStandalonePayments(array $paymentTypes, ?int $userId): int {
        for ($index = 0; $index < $this->standalonePayments; $index++) {
            $spec = $this->generatedStandalonePayment($index, $paymentTypes);

            $dueDate = DateTimeHelper::currentUTCDateTime()->modify(sprintf('%+d days', $spec['dueInDays']));

            $payment = QrPayments::getInstance()->getPayments()->createPayment(
                type: $paymentTypes[$spec['paymentType']],
                amount: $spec['amount'],
                email: $spec['email'],
                dueDate: $dueDate,
            );

            $this->applyOutcome($payment, $spec['outcome'], $userId, $spec['paidAmount']);

            $this->reportProgress($index + 1, $this->standalonePayments);
        }

        return $this->standalonePayments;
    }

    /**
     * `$index` picks the subscription type (round-robin), how many payment
     * cycles to stamp out (1-12, via `SubscriptionsService::advance()`),
     * how many of those come out paid (the rest stay pending), and the
     * final status (90% active, 5% paused, 5% cancelled) — deterministically,
     * so the same `$index` always produces the same subscriber, whatever
     * `$subscriptions` is set to.
     *
     * @param int $index
     * @param array<string, SubscriptionType> $subscriptionTypes Handle => type, from `seedSubscriptionTypes()`.
     * @return array{email: string, name: string, subscriptionType: string, cycles: int, paidCount: int, finalStatus: string}
     */
    private function generatedSubscriber(int $index, array $subscriptionTypes): array {
        $typeHandles = array_keys($subscriptionTypes);
        $cycles = ($index % 12) + 1;

        [$name, $email] = $this->generatedPerson($index);

        return [
            'email' => $email,
            'name' => $name,
            'subscriptionType' => $typeHandles[$index % count($typeHandles)],
            'cycles' => $cycles,
            'paidCount' => max(0, $cycles - 1 - ($index % 2)),
            'finalStatus' => match ($index % 20) {
                0 => Subscription::STATUS_CANCELLED,
                1 => Subscription::STATUS_PAUSED,
                default => Subscription::STATUS_ACTIVE,
            },
        ];
    }

    /**
     * `$index` picks the payment type (round-robin) and the outcome (cycles
     * through `STANDALONE_OUTCOMES`), with the amount and due date varied
     * just enough to not all look identical — deterministically, same
     * reasoning as `generatedSubscriber()`. Offset well clear of
     * `$subscriptions`' own index range so the two never generate the same
     * `name`/`email` pair.
     *
     * @param int $index
     * @param array<string, PaymentType> $paymentTypes Handle => type, from `seedPaymentTypes()`.
     * @return array{paymentType: string, email: string, name: string, amount: float, dueInDays: int, outcome: string, paidAmount: ?float}
     */
    private function generatedStandalonePayment(int $index, array $paymentTypes): array {
        $typeHandles = array_keys($paymentTypes);
        $outcome = self::STANDALONE_OUTCOMES[$index % count(self::STANDALONE_OUTCOMES)];
        $amount = round(15 + ($index % 50) * 0.97, 2);

        [$name, $email] = $this->generatedPerson($this->subscriptions + $index);

        return [
            'paymentType' => $typeHandles[$index % count($typeHandles)],
            'email' => $email,
            'name' => $name,
            'amount' => $amount,
            'dueInDays' => $outcome === 'expired' ? -(($index % 10) + 1) : (($index % 20) + 1),
            'outcome' => $outcome,
            'paidAmount' => match ($outcome) {
                'partial' => round($amount * 0.4, 2),
                'overpaid' => round($amount * 1.25, 2),
                default => null,
            },
        ];
    }

    /**
     * Deterministically turns a plain index into a `name`/`email` pair,
     * cycling through every `FIRST_NAMES` × `LAST_NAMES` combination (400
     * of them) before appending a numeric suffix to stay unique beyond that.
     *
     * @param int $index
     * @return array{0: string, 1: string} [name, email]
     */
    private function generatedPerson(int $index): array {
        $firstCount = count(self::FIRST_NAMES);
        $lastCount = count(self::LAST_NAMES);

        $first = self::FIRST_NAMES[$index % $firstCount];
        $last = self::LAST_NAMES[intdiv($index, $firstCount) % $lastCount];
        $suffix = intdiv($index, $firstCount * $lastCount);

        $slug = strtolower(str_replace(' ', '', $first) . '.' . str_replace(' ', '', $last)) . ($suffix > 0 ? $suffix : '');

        return ["{$first} {$last}", "{$slug}@seed.test"];
    }

    /**
     * Prints a running count every 10 items (plus the very last one) rather
     * than one line per item — with `$subscriptions`/`$standalonePayments`
     * in the hundreds, a line each would mostly just be noise.
     *
     * @param int $done
     * @param int $total
     * @return void
     */
    private function reportProgress(int $done, int $total): void {
        if ($done % 10 === 0 || $done === $total) {
            $this->stdout("  {$done} / {$total}\n");
        }
    }

    /**
     * Pushes the status-history entries that make `Payment::computeStatus()`
     * (run again in `beforeSave()` on the resave below) land on the given
     * outcome, then resaves — same mechanism the CP's "Mark as paid"/"Add
     * payment"/"Refund"/"Cancel" buttons use, just without the HTTP round
     * trip. `pending` and `expired` need no history at all: a brand-new
     * payment is already `pending`, and `expired` is purely a function of
     * `dueDate` having passed (see `Payment::getStatus()`), so there's
     * nothing to resave for either.
     *
     * @param Payment $payment
     * @param string $outcome One of 'pending', 'expired', 'paid', 'partial', 'overpaid', 'awaiting_confirmation', 'cancelled', 'refunded'.
     * @param int|null $userId
     * @param float|null $paidAmount Required for 'partial'/'overpaid' — how much was actually paid.
     * @return void
     */
    private function applyOutcome(Payment $payment, string $outcome, ?int $userId, ?float $paidAmount = null): void {
        match ($outcome) {
            'pending', 'expired' => null,
            'paid' => $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYMENT, $payment->getAmount(), $userId),
            'partial', 'overpaid' => $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYMENT, Payment::parseMoney($paidAmount), $userId),
            'awaiting_confirmation' => $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYER_REPORTED, null, null),
            'cancelled' => $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_CANCELLATION, null, $userId),
            'refunded' => $this->applyRefundOutcome($payment, $userId),
            default => throw new InvalidArgumentException("Unknown seed outcome: {$outcome}"),
        };

        if (!\in_array($outcome, ['pending', 'expired'], true)) {
            if (!Craft::$app->getElements()->saveElement($payment)) {
                throw new InvalidArgumentException("Failed to apply outcome \"{$outcome}\" to payment #{$payment->id}: " . json_encode($payment->getErrors()));
            }
        }
    }

    /**
     * A refund only makes sense against a payment that was first actually
     * paid in full, so this pushes both entries — the second nets the
     * balance back to zero, which is what `computeStatus()` keys off to
     * land on `STATUS_REFUNDED` instead of back on `STATUS_PENDING`.
     *
     * @param Payment $payment
     * @param int|null $userId
     * @return void
     */
    private function applyRefundOutcome(Payment $payment, ?int $userId): void {
        $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYMENT, $payment->getAmount(), $userId);
        $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_REFUND, $payment->getAmount(), $userId);
    }
}
