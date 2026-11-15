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

use digitalastronaut\craftqrpayments\elements\Subscription;

use yii\db\Expression;

/**
 * Class SubscriptionQuery
 *
 * Note: `$status` is intentionally left inherited from `ElementQuery`
 * rather than redeclared — same reasoning as `PaymentQuery`. Craft's
 * native enabled/disabled toggle stays an independent axis from
 * `Subscription`'s own active/paused/cancelled status, which routes
 * through `statusCondition()` below.
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

    public ?string $nextDueDateBefore = null;

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
     * @param string $value UTC datetime string — matches rows whose `nextDueDate` is set and on or before this.
     * @return SubscriptionQuery
     */
    public function nextDueDateBefore(string $value): static {
        $this->nextDueDateBefore = $value;
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
            'qr_payments_subscriptions.amount',
            'qr_payments_subscriptions.interval',
            'qr_payments_subscriptions.status',
            'qr_payments_subscriptions.nextDueDate',
            'qr_payments_subscriptions.startedAt',
            'qr_payments_subscriptions.endedAt',
        ]);

        if ($this->subscriptionTypeId !== null) {
            $this->subQuery->andWhere(['qr_payments_subscriptions.subscriptionTypeId' => $this->subscriptionTypeId]);
        }

        if ($this->userId !== null) {
            $this->subQuery->andWhere(['qr_payments_subscriptions.userId' => $this->userId]);
        }

        if ($this->email !== null) {
            $this->subQuery->andWhere(Db::parseParam('qr_payments_subscriptions.email', $this->email));
        }

        if ($this->nextDueDateBefore !== null) {
            $this->subQuery->andWhere([
                'and',
                ['not', ['qr_payments_subscriptions.nextDueDate' => null]],
                ['<=', 'qr_payments_subscriptions.nextDueDate', Db::prepareDateForDb($this->nextDueDateBefore)],
            ]);
        }

        return parent::beforePrepare();
    }

    /**
     * @param string $status
     * @return mixed
     */
    protected function statusCondition(string $status): mixed {
        return match ($status) {
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PAUSED,
            Subscription::STATUS_CANCELLED => ['qr_payments_subscriptions.status' => $status],
            default => parent::statusCondition($status),
        };
    }

    /**
     * Returns the number of matching subscriptions per status, grouped at
     * the database level rather than counted in a PHP loop — same pattern
     * `PaymentQuery::statusCounts()` uses, simpler here since `status`
     * doesn't need an "effective" (date-based) recomputation the way
     * `Payment`'s does.
     *
     * @return array<string, int> Status handle => count
     */
    public function statusCounts(): array {
        $db = Craft::$app->getDb();

        $query = clone $this;
        $query->orderBy(null);
        $query->prepare($db->getQueryBuilder());

        $rows = (clone $query->subQuery)
            ->select(['status' => 'qr_payments_subscriptions.status', 'count' => new Expression('COUNT(*)')])
            ->groupBy(['qr_payments_subscriptions.status'])
            ->all();

        return array_map('intval', array_column($rows, 'count', 'status'));
    }
}
