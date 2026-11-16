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
use craft\helpers\Cp;

use digitalastronaut\craftqrpayments\elements\Payment;

use yii\base\NotSupportedException;

/**
 * Class PaymentFieldsField
 *
 * Renders `Payment`'s user-entered `amount` as one block in the main
 * content area of the native element edit screen, the same "code-only
 * field layout" pattern `StatusHistoryField` uses. The linked account/guest
 * email live in the sidebar instead (see `Payment::ownerFieldHtml()`).
 *
 * Only rendered while the payment is still an unpublished draft: `amount`
 * is fixed for good the moment a payment is actually created
 * (`beforeValidate()`/`beforeSave()` derive the beneficiary/IBAN/BIC
 * snapshot and the QR code from it right then), and `Payment::metadata()`
 * already surfaces it read-only in the sidebar afterward — letting it stay
 * editable past that point would desync it from a payment's recorded
 * status history.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class PaymentFieldsField extends FieldLayoutElement {
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
        if (!$element instanceof Payment || !$element->getIsUnpublishedDraft()) return null;

        return $this->amountFieldHtml($element, $static);
    }

    /**
     * @param Payment $element
     * @param bool $static
     * @return string
     */
    private function amountFieldHtml(Payment $element, bool $static): string {
        $amount = $element->getAmount();

        return Cp::moneyFieldHtml([
            'label' => Craft::t('qr-payments', 'Amount'),
            'instructions' => Craft::t('qr-payments', 'The amount to request from the payer.'),
            'id' => 'amount',
            'name' => 'amount',
            'currency' => Payment::CURRENCY,
            'currencyLabel' => '(EUR) €',
            'value' => $amount ? $amount->getAmount() / 100 : null,
            'required' => true,
            'disabled' => $static,
            'errors' => $element->getErrors('amount'),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function actionMenuItems(?ElementInterface $element = null, bool $static = false): array {
        return [];
    }
}
