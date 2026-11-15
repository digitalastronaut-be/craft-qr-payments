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

namespace digitalastronaut\craftqrpayments\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\StringHelper;
use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\helpers\Table;

use DateTime;

/**
 * Class m261113_180000_make_subscriptions_elements
 *
 * `Subscription` becomes a real Craft element (like `Payment`) instead of
 * a plain model — a site can have a lot of subscribers, and the native
 * paginated, query-backed element index scales where the hand-rolled
 * `Craft.VueAdminTable` listing it replaces did not.
 *
 * `qr_payments_subscriptions.id` stops being its own primary key and
 * becomes a FK to `elements.id`, same as every other content table. Any
 * existing rows need a real `elements`/`elements_sites` row minted for
 * them first — captured and re-inserted with a fresh element id here,
 * same "don't silently drop real data" approach the earlier subscription
 * migrations used, rather than assuming the table is empty.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.7.0-beta
 */
class m261113_180000_make_subscriptions_elements extends Migration {
    public function safeUp(): bool {
        $existingRows = (new Query())
            ->from(Table::QR_PAYMENTS_SUBSCRIPTIONS)
            ->all();

        $this->dropForeignKeys();
        $this->dropTableIfExists(Table::QR_PAYMENTS_SUBSCRIPTIONS);

        $this->createTables();
        $this->createIndexes();

        foreach ($existingRows as $row) {
            $this->reinsertAsElement($row);
        }

        $this->createElementForeignKey();

        return true;
    }

    public function safeDown(): bool {
        echo "    > m261113_180000_make_subscriptions_elements cannot be reverted automatically — the element-backed table shape has no equivalent plain-row rollback.\n";

        return false;
    }

    /**
     * @return void
     */
    private function createTables(): void {
        $this->createTable(Table::QR_PAYMENTS_SUBSCRIPTIONS, [
            'id' => $this->primaryKey(),

            'userId' => $this->integer()->null(),
            'email' => $this->string(255)->notNull(),
            'subscriptionTypeId' => $this->integer()->notNull(),

            'amount' => $this->decimal(12, 2)->null(),
            'interval' => $this->string(20)->null(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),

            'nextDueDate' => $this->dateTime()->null(),
            'startedAt' => $this->dateTime()->notNull(),
            'endedAt' => $this->dateTime()->null(),

            'uid' => $this->uid(),
        ]);
    }

    /**
     * @return void
     */
    private function createIndexes(): void {
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'userId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', false);
        $this->createIndex(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'status', false);

        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'userId', CraftTable::USERS, 'id', 'SET NULL', null);
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'subscriptionTypeId', Table::QR_PAYMENTS_SUBSCRIPTIONTYPES, 'id', 'CASCADE', null);
    }

    /**
     * @return void
     */
    private function createElementForeignKey(): void {
        $this->addForeignKey(null, Table::QR_PAYMENTS_SUBSCRIPTIONS, 'id', CraftTable::ELEMENTS, 'id', 'CASCADE', null);
    }

    /**
     * Mints a fresh `elements`/`elements_sites` row for an existing plain
     * row and re-inserts the row's own data under that new id.
     *
     * @param array $row
     * @return void
     */
    private function reinsertAsElement(array $row): void {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $elementUid = StringHelper::UUID();

        $this->insert(CraftTable::ELEMENTS, [
            'type' => Subscription::class,
            'enabled' => true,
            'archived' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => $elementUid,
        ]);

        $elementId = (int) $this->db->getLastInsertID();

        $this->insert(CraftTable::ELEMENTS_SITES, [
            'elementId' => $elementId,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'title' => $this->titleForRow($row),
            'enabled' => true,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        $this->insert(Table::QR_PAYMENTS_SUBSCRIPTIONS, [
            'id' => $elementId,
            'userId' => $row['userId'],
            'email' => $row['email'],
            'subscriptionTypeId' => $row['subscriptionTypeId'],
            'amount' => $row['amount'],
            'interval' => $row['interval'],
            'status' => $row['status'],
            'nextDueDate' => $row['nextDueDate'],
            'startedAt' => $row['startedAt'],
            'endedAt' => $row['endedAt'],
            'uid' => $elementUid,
        ]);
    }

    /**
     * @param array $row
     * @return string
     */
    private function titleForRow(array $row): string {
        if ($row['userId'] !== null) {
            $name = (new Query())
                ->select(['fullName'])
                ->from(CraftTable::USERS)
                ->where(['id' => $row['userId']])
                ->scalar();

            if ($name) return $name;
        }

        return $row['email'] ?: 'Subscription';
    }

    /**
     * @return void
     */
    private function dropForeignKeys(): void {
        $this->dropAllForeignKeysToTable(Table::QR_PAYMENTS_SUBSCRIPTIONS);
    }
}
