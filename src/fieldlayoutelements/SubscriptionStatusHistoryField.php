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
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;

use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\helpers\CpDateFormatter;
use digitalastronaut\craftqrpayments\helpers\EmailLogCell;
use digitalastronaut\craftqrpayments\QrPayments;

use Carbon\Carbon;
use yii\base\NotSupportedException;

/**
 * Class SubscriptionStatusHistoryField
 *
 * Renders `Subscription::getStatusTimeline()` — the real transition log
 * merged with synthesized `expired` spans — same table shape as
 * `StatusHistoryField` renders `Payment::getStatusHistory()`, but with a
 * "Status" column instead of "Type" (reusing `Subscription::statuses()`
 * for the label/color) and a date *range* for a synthesized span instead
 * of a single timestamp. A synthesized row is rendered muted (`.disabled`)
 * so it reads as calculated rather than a logged event.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class SubscriptionStatusHistoryField extends FieldLayoutElement {
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
        if (!$element instanceof Subscription) return null;

        $history = array_reverse($element->getStatusTimeline());
        $emailLog = QrPayments::getInstance()->getEmailLog()->getForSubscription($element->id);
        $rows = array_map(fn(array $entry) => $this->rowHtml($entry, $emailLog), $history);

        $table = Html::tag('table',
            Html::tag('thead', Html::tag('tr',
                Html::tag('th', Craft::t('qr-payments', 'Status')) .
                Html::tag('th', Craft::t('qr-payments', 'Date')) .
                Html::tag('th', Craft::t('qr-payments', 'Email'))
            )) .
            Html::tag('tbody', implode('', $rows)),
            ['class' => ['data', 'fullwidth']],
        );

        $zilch = empty($history) ? Html::tag('p', Craft::t('qr-payments', 'No status history yet.'), ['class' => 'zilch small']) : '';
        $label = Html::tag('div', Html::tag('label', Craft::t('qr-payments', 'Status history')), ['class' => 'heading']);

        // The `<hr>` separates this from `SubscriptionPaymentsField`, which
        // always renders directly above it in the field layout (see
        // `Subscription::buildFieldLayout()`) — the two read as one
        // continuous block otherwise.
        return Html::tag('hr') . Html::tag('div', $label . $table . $zilch, ['class' => 'field']);
    }

    /**
     * @param array $entry
     * @param array<string, array> $emailLog `EmailLogService::getForSubscription()`'s result, indexed by `historyEntryId`
     * @return string
     */
    private function rowHtml(array $entry, array $emailLog): string {
        $synthesized = !empty($entry['synthesized']);
        $statusDef = Subscription::statuses()[$entry['status']] ?? null;

        $statusCell = Cp::statusLabelHtml([
            'color' => $statusDef['color'] ?? Color::Gray,
            'label' => $statusDef['label'] ?? $entry['status'],
        ]);

        $from = DateTimeHelper::toDateTime($entry['date'] ?? null);
        $dateCell = $from ? Html::encode(CpDateFormatter::format($from)) : (Html::encode((string) ($entry['date'] ?? '')) ?: '-');

        if ($synthesized) {
            $until = DateTimeHelper::toDateTime($entry['until'] ?? null);
            $untilLabel = $until ? CpDateFormatter::format($until) : Craft::t('qr-payments', 'now');
            $dateCell .= ' – ' . Html::encode($untilLabel);
        } elseif ($from) {
            $dateCell .= ' - ' . Html::tag('span', Carbon::instance($from)->diffForHumans(), ['class' => 'light']);
        }

        $emailCell = $synthesized ? '-' : EmailLogCell::html($entry['id'] ?? null, $emailLog);

        return Html::tag('tr',
            Html::tag('td', $statusCell) .
            Html::tag('td', $dateCell) .
            Html::tag('td', $emailCell),
            $synthesized ? ['class' => 'disabled'] : [],
        );
    }

    /**
     * Same reasoning as `StatusHistoryField::actionMenuItems()` — no
     * single attribute backs this multi-row table to copy.
     *
     * @inheritdoc
     */
    protected function actionMenuItems(?ElementInterface $element = null, bool $static = false): array {
        return [];
    }
}
