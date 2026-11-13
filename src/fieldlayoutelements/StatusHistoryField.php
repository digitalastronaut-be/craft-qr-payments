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

        $history = array_reverse($element->getStatusHistory());
        $userIds = array_values(array_unique(array_filter(array_column($history, 'userId'))));
        $users = empty($userIds) ? [] : User::find()->id($userIds)->indexBy('id')->all();
        $rows = array_map(fn(array $entry) => $this->rowHtml($entry, $users), $history);

        $table = Html::tag('table',
            Html::tag('thead', Html::tag('tr',
                Html::tag('th', Craft::t('qr-payments', 'Type')) .
                Html::tag('th', Craft::t('qr-payments', 'Date')) .
                Html::tag('th', Craft::t('qr-payments', 'Updated by')) .
                Html::tag('th', Craft::t('qr-payments', 'Amount')) .
                Html::tag('th', Craft::t('qr-payments', 'Note'))
            )) .
            Html::tag('tbody', implode('', $rows)),
            ['class' => ['data', 'fullwidth']],
        );

        $zilch = empty($history) ? Html::tag('p', Craft::t('qr-payments', 'No status history yet.'), ['class' => 'zilch small']) : '';
        $label = Html::tag('div', Html::tag('label',  Craft::t('qr-payments', 'Status history')), ['class' => 'heading']);

        return Html::tag('div', $label . $table . $zilch, ['class' => 'field']);
    }

    /**
     * @param array $entry
     * @param User[] $users indexed by id
     * @return string
     */
    private function rowHtml(array $entry, array $users): string {
        $typeDef = Payment::historyTypes()[$entry['type']] ?? null;

        $typeCell = Cp::statusLabelHtml([
            'color' => $typeDef['color'] ?? Color::Gray,
            'label' => $typeDef['label'] ?? ($entry['type'] ?? ''),
        ]);

        $date = DateTimeHelper::toDateTime($entry['date']);
        $dateCell = $date ? Html::encode(Craft::$app->getFormatter()->asDatetime($date, 'short')) : Html::encode($entry['date'] ?? '');

        if ($date) $dateCell .= ' - ' . Html::tag('span', Carbon::instance($date)->diffForHumans(), ['class' => 'light']);

        $userId = $entry['userId'] ?? null;
        $userCell = match (true) {
            $userId && isset($users[$userId]) => $users[$userId]->getName(),
            ($entry['type'] ?? null) === Payment::HISTORY_TYPE_PAYER_REPORTED => Craft::t('qr-payments', 'Payer'),
            default => Craft::t('qr-payments', 'System'),
        };

        $amount = $entry['amount'] ?? null;
        $amountCell = ($amount !== null && $amount !== '') ? Html::encode(Craft::$app->getFormatter()->asCurrency($amount, Payment::CURRENCY)) : '';

        $noteCell = Html::encode($entry['note'] ?? '');

        return Html::tag('tr',
            Html::tag('td', $typeCell) .
            Html::tag('td', $dateCell) .
            Html::tag('td', Html::encode($userCell)) .
            Html::tag('td', $amountCell) .
            Html::tag('td', $noteCell),
        );
    }

    /**
     * @inheritdoc
     */
    protected function actionMenuItems(?ElementInterface $element = null, bool $static = false): array {
        $items = [];

        if (Craft::$app->getUser()->getIsAdmin()) $items[] = $this->copyAttributeAction();

        return $items;
    }
}
