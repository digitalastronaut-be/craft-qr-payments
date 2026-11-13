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
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;

use digitalastronaut\craftqrpayments\elements\Payment;

use yii\db\Expression;

/**
 * Class PaymentQuery
 *
 * Note: `$status` is intentionally left inherited from ElementQuery rather
 * than redeclared. The base default (`[Element::STATUS_ENABLED]`) keeps
 * Craft's native enabled/disabled toggle working as an independent axis
 * from Payment's own pending/paid/expired/cancelled status — callers that
 * want to filter by the latter do so explicitly via ->status('paid') etc.,
 * which routes through statusCondition() below.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 *
 * @method self beneficiaryName(string $value)
 * @method self iban(string $value)
 * @method self remittanceReference(string|array $value)
 */
class PaymentQuery extends ElementQuery {
    public string|array|null $beneficiaryName = null;
    public string|array|null $iban = null;
    public string|array|null $remittanceReference = null;

    public ?float $amountFrom = null;
    public ?float $amountTo = null;

    public ?string $dueDateFrom = null;
    public ?string $dueDateTo = null;

    /**
     * @param string $value
     * @return PaymentQuery
     */
    public function beneficiaryName(string $value): static {
        $this->beneficiaryName = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return PaymentQuery
     */
    public function iban(string $value): static {
        $this->iban = $value;
        return $this;
    }

    /**
     * @param string|array $value A single reference, or a list of them to match any of.
     * @return PaymentQuery
     */
    public function remittanceReference(string|array $value): static {
        $this->remittanceReference = $value;
        return $this;
    }

    /**
     * @param float $from
     * @param float $to
     * @return PaymentQuery
     */
    public function amountBetween(float $from, float $to): static {
        $this->amountFrom = $from;
        $this->amountTo = $to;
        return $this;
    }

    /**
     * @param string $from
     * @param string $to
     * @return PaymentQuery
     */
    public function dueDateBetween(string $from, string $to): static {
        $this->dueDateFrom = $from;
        $this->dueDateTo = $to;
        return $this;
    }

    /**
     * @return bool
     */
    protected function beforePrepare(): bool {
        $this->joinElementTable('qr_payments_payments');

        $this->query->addSelect([
            'qr_payments_payments.email',
            'qr_payments_payments.beneficiaryName',
            'qr_payments_payments.iban',
            'qr_payments_payments.bic',
            'qr_payments_payments.amount',
            'qr_payments_payments.remittanceReference',
            'qr_payments_payments.remittanceText',
            'qr_payments_payments.qrCode',
            'qr_payments_payments.status',
            'qr_payments_payments.statusHistory',
            'qr_payments_payments.dueDate',
            'qr_payments_payments.confirmedAt',
            'qr_payments_payments.confirmedByUserId',
        ]);

        if ($this->beneficiaryName !== null) {
            $this->subQuery->andWhere(Db::parseParam('qr_payments_payments.beneficiaryName', $this->beneficiaryName));
        }

        if ($this->iban !== null) {
            $this->subQuery->andWhere(Db::parseParam('qr_payments_payments.iban', $this->iban));
        }

        if ($this->remittanceReference !== null) {
            $this->subQuery->andWhere(Db::parseParam('qr_payments_payments.remittanceReference', $this->remittanceReference));
        }

        if ($this->amountFrom !== null && $this->amountTo !== null) {
            $this->subQuery->andWhere([
                'between',
                'qr_payments_payments.amount',
                $this->amountFrom,
                $this->amountTo,
            ]);
        }

        if ($this->dueDateFrom !== null && $this->dueDateTo !== null) {
            $this->subQuery->andWhere([
                'between',
                'qr_payments_payments.dueDate',
                Db::prepareDateForDb($this->dueDateFrom),
                Db::prepareDateForDb($this->dueDateTo),
            ]);
        }

        return parent::beforePrepare();
    }

    /**
     * Mirrors Payment::getStatus(): `expired` isn't a stored value, it's a
     * `pending` payment whose `dueDate` has passed, so the query-side
     * condition has to reconstruct that same date comparison rather than
     * just matching the `status` column.
     *
     * @param string $status
     * @return mixed
     */
    protected function statusCondition(string $status): mixed {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        return match ($status) {
            Payment::STATUS_PENDING => [
                'and',
                ['qr_payments_payments.status' => Payment::STATUS_PENDING],
                ['or', ['qr_payments_payments.dueDate' => null], ['>=', 'qr_payments_payments.dueDate', $now]],
            ],
            Payment::STATUS_EXPIRED => [
                'and',
                ['qr_payments_payments.status' => Payment::STATUS_PENDING],
                ['not', ['qr_payments_payments.dueDate' => null]],
                ['<', 'qr_payments_payments.dueDate', $now],
            ],
            Payment::STATUS_AWAITING_CONFIRMATION, Payment::STATUS_PAID, Payment::STATUS_PARTIAL, Payment::STATUS_OVERPAID, Payment::STATUS_CANCELLED, Payment::STATUS_REFUNDED => ['qr_payments_payments.status' => $status],
            default => parent::statusCondition($status),
        };
    }

    /**
     * Returns the number of matching payments per effective status (see
     * statusCondition() for why `expired` isn't a stored value), grouped
     * at the database level rather than counted in a PHP loop.
     *
     * @return array<string, int> Status handle => count
     */
    public function statusCounts(): array {
        $db = Craft::$app->getDb();

        $query = clone $this;
        $query->orderBy(null);
        $query->prepare($db->getQueryBuilder());

        $statusExpression = self::effectiveStatusExpression();

        $rows = (clone $query->subQuery)
            ->select(['status' => $statusExpression, 'count' => new Expression('COUNT(*)')])
            ->groupBy([$statusExpression])
            ->all();

        return array_map('intval', array_column($rows, 'count', 'status'));
    }

    /**
     * Returns the number of matching payments *and* their summed `amount`,
     * per effective status, bucketed into calendar day/week/month periods —
     * one grouped query rather than a PHP loop (or two separate count/sum
     * queries). Each row's `bucket` is that period's start date (`Y-m-d`
     * for day/week, `Y-m-01` for month) — weeks start on Monday, matching
     * Carbon's default `startOfWeek()`.
     *
     * @param string $unit One of 'day', 'week', 'month'
     * @return array{status: string, bucket: string, count: int, amount: float}[]
     */
    public function statusTotalsByPeriod(string $unit): array {
        $db = Craft::$app->getDb();

        $query = clone $this;
        $query->orderBy(null);
        $query->prepare($db->getQueryBuilder());

        $statusExpression = self::effectiveStatusExpression();
        $bucketExpression = self::periodBucketExpression($unit);

        $rows = (clone $query->subQuery)
            ->select([
                'status' => $statusExpression,
                'bucket' => $bucketExpression,
                'count' => new Expression('COUNT(*)'),
                'amount' => new Expression('SUM([[qr_payments_payments.amount]])'),
            ])
            ->groupBy([$statusExpression, $bucketExpression])
            ->all();

        foreach ($rows as &$row) {
            $row['count'] = (int) $row['count'];
            $row['amount'] = (float) $row['amount'];
        }

        return $rows;
    }

    /**
     * @return string|null UTC datetime string of the earliest matching
     * payment's `dateCreated`, or null if there are no matches
     */
    public function earliestDateCreated(): ?string {
        $db = Craft::$app->getDb();

        $query = clone $this;
        $query->orderBy(null);
        $query->prepare($db->getQueryBuilder());

        return (clone $query->subQuery)
            ->select(['earliest' => new Expression('MIN([[elements.dateCreated]])')])
            ->scalar() ?: null;
    }

    /**
     * SQL `CASE` expression reconstructing Payment::getStatus()'s
     * `pending`-past-`dueDate`-becomes-`expired` rule, so status can be
     * grouped by its effective value rather than the raw stored column.
     *
     * @return Expression
     */
    private static function effectiveStatusExpression(): Expression {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        return new Expression(
            'CASE ' .
            'WHEN [[qr_payments_payments.status]] = :pendingStatus AND ([[qr_payments_payments.dueDate]] IS NULL OR [[qr_payments_payments.dueDate]] >= :now1) THEN :pendingLabel ' .
            'WHEN [[qr_payments_payments.status]] = :pendingStatus AND [[qr_payments_payments.dueDate]] IS NOT NULL AND [[qr_payments_payments.dueDate]] < :now2 THEN :expiredLabel ' .
            'ELSE [[qr_payments_payments.status]] END',
            [
                ':pendingStatus' => Payment::STATUS_PENDING,
                ':now1' => $now,
                ':now2' => $now,
                ':pendingLabel' => Payment::STATUS_PENDING,
                ':expiredLabel' => Payment::STATUS_EXPIRED,
            ],
        );
    }

    /**
     * SQL expression truncating `dateCreated` down to the start of its
     * enclosing day/week/month, so rows can be grouped into
     * calendar-aligned buckets at the database level.
     *
     * @param string $unit One of 'day', 'week', 'month'
     * @return Expression
     */
    private static function periodBucketExpression(string $unit): Expression {
        return match ($unit) {
            'month' => new Expression("DATE_FORMAT([[elements.dateCreated]], '%Y-%m-01')"),
            'week' => new Expression('DATE_SUB(DATE([[elements.dateCreated]]), INTERVAL WEEKDAY([[elements.dateCreated]]) DAY)'),
            default => new Expression('DATE([[elements.dateCreated]])'),
        };
    }

    /**
     * Returns one row per year+month that has at least one payment, newest
     * first — used by `Payment::defineSources()` to build the year/month
     * source tree in the element index without hardcoding which periods
     * actually have payments in them. Each row's `count` badges that
     * month's source directly, and sums up into its year's badge.
     *
     * @return array{year: string, month: string, count: int}[]
     */
    public function groupedByMonth(): array {
        $db = Craft::$app->getDb();

        $query = clone $this;
        $query->orderBy(null);
        $query->prepare($db->getQueryBuilder());

        $yearExpression = new Expression('EXTRACT(YEAR FROM [[elements.dateCreated]])');
        $monthExpression = new Expression('EXTRACT(MONTH FROM [[elements.dateCreated]])');

        $rows = (clone $query->subQuery)
            ->select([
                'year' => $yearExpression,
                'month' => $monthExpression,
                'count' => new Expression('COUNT(*)'),
            ])
            ->groupBy([$yearExpression, $monthExpression])
            ->orderBy([
                'year' => SORT_DESC,
                'month' => SORT_DESC,
            ])
            ->all();

        foreach ($rows as &$row) {
            $row['count'] = (int) $row['count'];
        }

        return $rows;
    }
}
