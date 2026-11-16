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

namespace digitalastronaut\craftqrpayments\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldLayoutElement;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\helpers\CpDateFormatter;
use digitalastronaut\craftqrpayments\helpers\EmailLogCell;
use digitalastronaut\craftqrpayments\QrPayments;

use Carbon\Carbon;
use yii\base\NotSupportedException;

/**
 * Class StatusHistoryField
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class StatusHistoryField extends FieldLayoutElement {
    /**
     * @return string
     * @throws NotSupportedException always, this element isn't user-configurable.
     */
    public function selectorHtml(): string {
        throw new NotSupportedException(sprintf('%s should not be included in user-modifiable field layouts.', self::class));
    }

    /**
     * @param ElementInterface|null $element
     * @param bool $static
     * @return string|null
     */
    public function formHtml(?ElementInterface $element = null, bool $static = false): ?string {
        if (!$element instanceof Payment) return null;

        $chronological = $element->getStatusHistory();
        $userIds = array_values(array_unique(array_filter(array_column($chronological, 'userId'))));
        $users = empty($userIds) ? [] : User::find()->id($userIds)->indexBy('id')->all();
        $emailLog = QrPayments::getInstance()->getEmailLog()->getForPayment($element->id);

        // Each row's status chip is the payment's *resulting* status right
        // after that entry — not the generic kind of action it was (a
        // `HISTORY_TYPE_PAYMENT` entry is labeled "Paid" there even for a
        // partial amount) — so it's computed here against a growing
        // prefix of the history in its real chronological order, one
        // entry at a time, before the whole thing is reversed for
        // newest-first display.
        $rows = [];

        foreach ($chronological as $i => $entry) {
            $resultingStatus = $element->computeStatus(array_slice($chronological, 0, $i + 1));
            $rows[] = $this->rowHtml($entry, $resultingStatus, $users, $emailLog);
        }

        $rows = array_reverse($rows);

        $table = Html::tag('table',
            Html::tag('thead', Html::tag('tr',
                Html::tag('th', Craft::t('qr-payments', 'Status')) .
                Html::tag('th', Craft::t('qr-payments', 'Date')) .
                Html::tag('th', Craft::t('qr-payments', 'Updated by')) .
                Html::tag('th', Craft::t('qr-payments', 'Amount')) .
                Html::tag('th', Craft::t('qr-payments', 'Note')) .
                Html::tag('th', Craft::t('qr-payments', 'Email'))
            )) .
            Html::tag('tbody', implode('', $rows)),
            ['class' => ['data', 'fullwidth']],
        );

        $zilch = empty($chronological) ? Html::tag('p', Craft::t('qr-payments', 'No status history yet.'), ['class' => 'zilch small']) : '';
        $label = Html::tag('div', Html::tag('label',  Craft::t('qr-payments', 'Status history')), ['class' => 'heading']);

        return Html::tag('div', $label . $table . $zilch, ['class' => 'field']);
    }

    /**
     * @param array $entry
     * @param string $resultingStatus One of `Payment::STATUS_*` — the status this entry left the payment in, from `formHtml()`'s own running `computeStatus()` call.
     * @param User[] $users indexed by id
     * @param array<string, array> $emailLog `EmailLogService::getForPayment()`'s result, indexed by `historyEntryId`
     * @return string
     */
    private function rowHtml(array $entry, string $resultingStatus, array $users, array $emailLog): string {
        $statusDef = Payment::statuses()[$resultingStatus] ?? null;

        $statusCell = Cp::statusLabelHtml([
            'color' => $statusDef['color'] ?? Color::Gray,
            'label' => $statusDef['label'] ?? $resultingStatus,
        ]);

        $date = DateTimeHelper::toDateTime($entry['date']);
        $dateCell = $date ? Html::encode(CpDateFormatter::format($date)) : Html::encode($entry['date'] ?? '');

        if ($date) $dateCell .= ' - ' . Html::tag('span', Carbon::instance($date)->diffForHumans(), ['class' => 'light']);

        $userId = $entry['userId'] ?? null;
        $userCell = match (true) {
            $userId && isset($users[$userId]) => $users[$userId]->getName(),
            ($entry['type'] ?? null) === Payment::HISTORY_TYPE_PAYER_REPORTED => Craft::t('qr-payments', 'Payer'),
            default => Craft::t('qr-payments', 'System'),
        };

        $amount = $entry['amount'] ?? null;
        $amountCell = ($amount !== null && $amount !== '') ? Html::encode(Craft::$app->getFormatter()->asCurrency($amount, Payment::CURRENCY)) : '-';

        $noteCell = Html::encode($entry['note'] ?? '') ?: '-';

        $emailCell = EmailLogCell::html($entry['id'] ?? null, $emailLog);

        return Html::tag('tr',
            Html::tag('td', $statusCell) .
            Html::tag('td', $dateCell) .
            Html::tag('td', Html::encode($userCell)) .
            Html::tag('td', $amountCell) .
            Html::tag('td', $noteCell) .
            Html::tag('td', $emailCell),
        );
    }

    /**
     * `copyAttributeAction()` lives on `craft\fieldlayoutelements\BaseField`,
     * not the plain `craft\base\FieldLayoutElement` this class extends — it
     * doesn't apply here anyway, since there's no single attribute backing
     * this multi-row table to copy.
     *
     * @inheritdoc
     */
    protected function actionMenuItems(?ElementInterface $element = null, bool $static = false): array {
        return [];
    }
}
