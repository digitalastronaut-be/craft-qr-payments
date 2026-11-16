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
use craft\helpers\Html;
use craft\helpers\MoneyHelper;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\helpers\HumanDuration;

use yii\base\NotSupportedException;

/**
 * Class SubscriptionPaymentsField
 *
 * Read-only table of every `Payment` this subscription has stamped out
 * (`SubscriptionsService::advance()`), newest first — the only place that
 * link between the two is visible from the subscription side;
 * `Payment::getSubscription()`/`Payment::getType()` already cover reading
 * it from the payment side. Still rendered (empty) for a brand-new
 * unpublished draft, which can't possibly have generated a payment yet —
 * same as `SubscriptionStatusHistoryField` always rendering its own empty
 * state, rather than the field disappearing outright.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class SubscriptionPaymentsField extends FieldLayoutElement {
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

        $payments = ($element->id && !$element->getIsUnpublishedDraft())
            ? Payment::find()
                ->subscriptionId($element->id)
                ->status(null)
                // `id` as a tiebreaker: a batch of payments stamped out in
                // quick succession (e.g. `SubscriptionsService::advance()`
                // run several times in a row) can land in the same
                // `dateCreated` second, which would otherwise leave their
                // relative order undefined.
                ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
                ->all()
            : [];

        $rows = array_map(fn(Payment $payment) => $this->rowHtml($payment), $payments);

        $table = Html::tag('table',
            Html::tag('thead', Html::tag('tr',
                Html::tag('th', Craft::t('qr-payments', 'Reference')) .
                Html::tag('th', Craft::t('qr-payments', 'Status')) .
                Html::tag('th', Craft::t('qr-payments', 'Amount')) .
                Html::tag('th', Craft::t('qr-payments', 'Expires in')) .
                Html::tag('th', Craft::t('qr-payments', 'Actions'))
            )) .
            Html::tag('tbody', implode('', $rows)),
            ['class' => ['data', 'fullwidth']],
        );

        $zilch = empty($payments) ? Html::tag('p', Craft::t('qr-payments', 'No payments'), ['class' => 'zilch small']) : '';
        $label = Html::tag('div', Html::tag('label', Craft::t('qr-payments', 'Payments')), ['class' => 'heading']);

        return Html::tag('div', $label . $table . $zilch, ['class' => 'field']);
    }

    /**
     * @param Payment $payment
     * @return string
     */
    private function rowHtml(Payment $payment): string {
        $statusDef = Payment::statuses()[$payment->getStatus()] ?? null;

        $statusCell = $statusDef !== null
            ? Cp::statusLabelHtml(['color' => $statusDef['color'], 'label' => $statusDef['label']])
            : Html::encode($payment->getStatus() ?? '');

        $referenceCell = Html::tag('a', Html::encode($payment->remittanceReference ?: $payment->title), [
            'href' => $payment->getCpEditUrl(),
        ]);

        $amountCell = Html::encode(MoneyHelper::toString($payment->getAmount()) ?: '');
        $expiresInCell = Html::encode(HumanDuration::until($payment->dueDate));
        $actionsCell = $payment->historyActionButtonsHtml(small: true);

        return Html::tag('tr',
            Html::tag('td', $referenceCell) .
            Html::tag('td', $statusCell) .
            Html::tag('td', $amountCell) .
            Html::tag('td', $expiresInCell) .
            Html::tag('td', $actionsCell),
        );
    }

    /**
     * @inheritdoc
     */
    protected function actionMenuItems(?ElementInterface $element = null, bool $static = false): array {
        return [];
    }
}
