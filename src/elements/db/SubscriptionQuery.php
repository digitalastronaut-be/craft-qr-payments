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

namespace digitalastronaut\craftqrpayments\elements\db;

use Craft;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\models\SubscriptionType;

use yii\db\Expression;

/**
 * Class SubscriptionQuery
 *
 * Note: `$status` is intentionally left inherited from `ElementQuery`
 * rather than redeclared — same reasoning as `PaymentQuery`. Craft's
 * native enabled/disabled toggle stays an independent axis from
 * `Subscription`'s own active/cancelled status, which routes through
 * `statusCondition()` below.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.7.0-beta
 *
 * @method self subscriptionTypeId(int $value)
 * @method self userId(int $value)
 * @method self email(string $value)
 */
class SubscriptionQuery extends ElementQuery {
    public ?int $subscriptionTypeId = null;
    public ?int $userId = null;
    public ?string $email = null;

    /**
     * @param int $value
     * @return SubscriptionQuery
     */
    public function subscriptionTypeId(int $value): static {
        $this->subscriptionTypeId = $value;
        return $this;
    }

    /**
     * @param int $value
     * @return SubscriptionQuery
     */
    public function userId(int $value): static {
        $this->userId = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return SubscriptionQuery
     */
    public function email(string $value): static {
        $this->email = $value;
        return $this;
    }

    /**
     * @return bool
     */
    protected function beforePrepare(): bool {
        $this->joinElementTable('qr_payments_subscriptions');

        $this->query->addSelect([
            'qr_payments_subscriptions.userId',
            'qr_payments_subscriptions.email',
            'qr_payments_subscriptions.subscriptionTypeId',
            'qr_payments_subscriptions.status',
            'qr_payments_subscriptions.statusHistory',
            'qr_payments_subscriptions.endedAt',
        ]);

        if ($this->subscriptionTypeId !== null) {
            $this->subQuery->andWhere(['qr_payments_subscriptions.subscriptionTypeId' => $this->subscriptionTypeId]);
        }

        if ($this->userId !== null) {
            $this->subQuery->andWhere(['qr_payments_subscriptions.userId' => $this->userId]);
        }

        if ($this->email !== null) {
            // Case-insensitive on purpose — same comparison Craft's own
            // `User` element uses for its own email uniqueness check —
            // so `SubscriptionsService::hasOutstandingSubscription()`
            // can't be bypassed just by varying how an email is cased.
            $this->subQuery->andWhere(new Expression(
                'LOWER([[qr_payments_subscriptions.email]]) = LOWER(:qrpEmail)',
                [':qrpEmail' => $this->email],
            ));
        }

        return parent::beforePrepare();
    }

    /**
     * `expired` mirrors `Subscription::getStatus()`'s virtual rule exactly:
     * an `active` subscription whose `coverageEndExpression()` has
     * already passed counts as `expired`, so `active` here has to
     * explicitly exclude that case too — otherwise the two filters would
     * overlap.
     *
     * @param string $status
     * @return mixed
     */
    protected function statusCondition(string $status): mixed {
        return match ($status) {
            Subscription::STATUS_ACTIVE => [
                'and',
                ['qr_payments_subscriptions.status' => Subscription::STATUS_ACTIVE],
                ['not', self::coverageEndedExpression()],
            ],
            Subscription::STATUS_EXPIRED => [
                'and',
                ['qr_payments_subscriptions.status' => Subscription::STATUS_ACTIVE],
                self::coverageEndedExpression(),
            ],
            Subscription::STATUS_REQUESTED,
            Subscription::STATUS_CANCELLED => ['qr_payments_subscriptions.status' => $status],
            default => parent::statusCondition($status),
        };
    }

    /**
     * Returns the number of matching subscriptions per effective status
     * (see `statusCondition()` for why `expired` isn't a stored value),
     * grouped at the database level rather than counted in a PHP loop —
     * same pattern `PaymentQuery::statusCounts()` uses.
     *
     * Groups a derived table's plain `status` column rather than the
     * `CASE` expression directly: MySQL's `ONLY_FULL_GROUP_BY` (the
     * default since 5.7) rejects grouping by a repeated non-aggregate
     * expression containing a correlated subquery, even when the
     * `GROUP BY` and `SELECT` copies are textually identical.
     *
     * @return array<string, int> Status handle => count
     */
    public function statusCounts(): array {
        $db = Craft::$app->getDb();

        $query = clone $this;
        $query->orderBy(null);
        $query->prepare($db->getQueryBuilder());

        $effectiveStatusSubQuery = (clone $query->subQuery)->select(['status' => self::effectiveStatusExpression()]);

        $rows = (new Query())
            ->select(['status', 'count' => new Expression('COUNT(*)')])
            ->from(['effectiveStatus' => $effectiveStatusSubQuery])
            ->groupBy(['status'])
            ->all();

        return array_map('intval', array_column($rows, 'count', 'status'));
    }

    /**
     * The SQL-level twin of `Subscription::getCoverageEnd()`: the date
     * through which this subscription is paid up — its latest `paid`/
     * `overpaid` linked `Payment`'s own `dueDate`, plus one interval (its
     * `SubscriptionType`'s) — or `NULL` if none of its payments have ever
     * been paid.
     *
     * The interval can't vary dynamically inside a single `DATE_ADD(...,
     * INTERVAL n UNIT)` call in SQL (only `n` can be an expression, not
     * `UNIT`), so this is a `CASE` with one `DATE_ADD` branch per
     * `SubscriptionType::INTERVAL_*`, each repeating the two correlated
     * subqueries below. Repeating them (rather than computing each once
     * in a derived table) avoids `LATERAL`, which MySQL only added in
     * 8.0.14 — a plain derived table can't reference this query's own
     * columns inside its own `FROM` clause.
     *
     * @return Expression
     */
    public static function coverageEndExpression(): Expression {
        $payments = Table::QR_PAYMENTS_PAYMENTS;
        $subscriptionTypes = Table::QR_PAYMENTS_SUBSCRIPTIONTYPES;

        $latestPaidDueDate =
            "(SELECT [[latestPaid.dueDate]] FROM {$payments} AS [[latestPaid]] " .
            'WHERE [[latestPaid.subscriptionId]] = [[qr_payments_subscriptions.id]] ' .
            'AND [[latestPaid.status]] IN (:paidStatus, :overpaidStatus) ' .
            'AND [[latestPaid.id]] = (' .
                "SELECT MAX([[p2.id]]) FROM {$payments} AS [[p2]] " .
                'WHERE [[p2.subscriptionId]] = [[qr_payments_subscriptions.id]] ' .
                'AND [[p2.status]] IN (:paidStatus, :overpaidStatus)' .
            '))';

        $interval =
            "(SELECT [[st.interval]] FROM {$subscriptionTypes} AS [[st]] " .
            'WHERE [[st.id]] = [[qr_payments_subscriptions.subscriptionTypeId]])';

        return new Expression(
            'CASE ' .
            "WHEN {$interval} = :daily THEN DATE_ADD({$latestPaidDueDate}, INTERVAL 1 DAY) " .
            "WHEN {$interval} = :weekly THEN DATE_ADD({$latestPaidDueDate}, INTERVAL 1 WEEK) " .
            "WHEN {$interval} = :biweekly THEN DATE_ADD({$latestPaidDueDate}, INTERVAL 2 WEEK) " .
            "WHEN {$interval} = :monthly THEN DATE_ADD({$latestPaidDueDate}, INTERVAL 1 MONTH) " .
            "WHEN {$interval} = :quarterly THEN DATE_ADD({$latestPaidDueDate}, INTERVAL 3 MONTH) " .
            "WHEN {$interval} = :yearly THEN DATE_ADD({$latestPaidDueDate}, INTERVAL 1 YEAR) " .
            'END',
            [
                ':paidStatus' => Payment::STATUS_PAID,
                ':overpaidStatus' => Payment::STATUS_OVERPAID,
                ':daily' => SubscriptionType::INTERVAL_DAILY,
                ':weekly' => SubscriptionType::INTERVAL_WEEKLY,
                ':biweekly' => SubscriptionType::INTERVAL_BIWEEKLY,
                ':monthly' => SubscriptionType::INTERVAL_MONTHLY,
                ':quarterly' => SubscriptionType::INTERVAL_QUARTERLY,
                ':yearly' => SubscriptionType::INTERVAL_YEARLY,
            ],
        );
    }

    /**
     * Boolean "has `coverageEndExpression()` already passed" check — the
     * SQL-level half of `Subscription::isPastDue()`.
     *
     * @return Expression
     */
    private static function coverageEndedExpression(): Expression {
        $coverageEnd = self::coverageEndExpression();
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        return new Expression(
            "(({$coverageEnd->expression}) IS NOT NULL AND ({$coverageEnd->expression}) < :coverageNow)",
            array_merge($coverageEnd->params, [':coverageNow' => $now]),
        );
    }

    /**
     * SQL `CASE` expression reconstructing `Subscription::getStatus()`'s
     * `active`-with-elapsed-coverage-becomes-`expired` rule, so status
     * can be grouped by its effective value rather than the raw stored
     * column — same idiom `PaymentQuery::effectiveStatusExpression()`
     * uses.
     *
     * @return Expression
     */
    private static function effectiveStatusExpression(): Expression {
        $ended = self::coverageEndedExpression();

        return new Expression(
            'CASE ' .
            "WHEN [[qr_payments_subscriptions.status]] = :activeStatus AND ({$ended->expression}) THEN :expiredLabel " .
            'ELSE [[qr_payments_subscriptions.status]] END',
            array_merge($ended->params, [
                ':activeStatus' => Subscription::STATUS_ACTIVE,
                ':expiredLabel' => Subscription::STATUS_EXPIRED,
            ]),
        );
    }
}
