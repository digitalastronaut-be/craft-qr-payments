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
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;

use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\jobs\SendTemplatedEmailJob;

use Throwable;

/**
 * Class EmailLogService
 *
 * Plain `qr_payments_email_log` row access — no `ActiveRecord`/`Record`
 * class, same as every other table this plugin owns (`Payment`/
 * `Subscription` aside, which are elements for other reasons). Every row
 * is written once, automatically, by `SendTemplatedEmailJob::execute()`;
 * this service only adds reading it back and resending from it.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
class EmailLogService extends Component {
    /**
     * @param array $attributes
     * @return int The new row's id.
     */
    public function log(array $attributes): int {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        Db::insert(Table::QR_PAYMENTS_EMAIL_LOG, $attributes + [
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ]);

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @param int $paymentId
     * @return array<string, array> Log rows indexed by `historyEntryId` (rows with none are skipped — they can't be matched to a status-history row anyway).
     */
    public function getForPayment(int $paymentId): array {
        return $this->indexedByHistoryEntry(['paymentId' => $paymentId]);
    }

    /**
     * @param int $subscriptionId
     * @return array<string, array> Same shape as `getForPayment()`.
     */
    public function getForSubscription(int $subscriptionId): array {
        return $this->indexedByHistoryEntry(['subscriptionId' => $subscriptionId]);
    }

    /**
     * @param array $where
     * @return array<string, array>
     */
    private function indexedByHistoryEntry(array $where): array {
        $rows = (new Query())
            ->from(Table::QR_PAYMENTS_EMAIL_LOG)
            ->where($where)
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();

        $indexed = [];

        foreach ($rows as $row) {
            if ($row['historyEntryId'] === null) continue;

            $indexed[$row['historyEntryId']] = $row;
        }

        return $indexed;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array {
        $row = (new Query())
            ->from(Table::QR_PAYMENTS_EMAIL_LOG)
            ->where(['id' => $id])
            ->one();

        return $row ?: null;
    }

    /**
     * Re-queues the exact job that produced `$logId`'s row, from the
     * `jobClass`/`jobParams` that row's original `SendTemplatedEmailJob::
     * execute()` call logged — those are already exactly the constructor
     * args that job's own `__sleep()` says it needs to reconstruct itself.
     * Runs it eagerly, same as every other notify call in this plugin.
     *
     * @param int $logId
     * @param int|null $userId The admin triggering the resend.
     * @return bool False if the log row doesn't exist, or the resend itself fails.
     */
    public function resend(int $logId, ?int $userId): bool {
        $row = $this->getById($logId);

        if ($row === null) return false;

        /** @var class-string<SendTemplatedEmailJob> $jobClass */
        $jobClass = $row['jobClass'];
        $params = \is_string($row['jobParams']) ? (Json::decode($row['jobParams']) ?: []) : (array) $row['jobParams'];

        try {
            $job = new $jobClass(array_merge($params, ['triggeredByUserId' => $userId]));

            Craft::$app->getQueue()->push($job);
            Craft::$app->queue->run();
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);

            return false;
        }

        return true;
    }
}
