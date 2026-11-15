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
use craft\helpers\Cp;

use digitalastronaut\craftqrpayments\elements\Payment;

use yii\base\NotSupportedException;

/**
 * Class PaymentFieldsField
 *
 * Renders `Payment`'s user-entered native attributes — the linked account,
 * email, and amount — as one block in the main content area of the native
 * element edit screen, the same "code-only field layout" pattern
 * `StatusHistoryField` and `SubscriptionFieldsField` use.
 *
 * The account field stays editable for the life of the payment — a guest
 * payment can be linked to an account later (e.g. once the payer registers),
 * same as `Subscription`'s own owner field. Email and amount, by contrast,
 * are only rendered while the payment is still an unpublished draft: both
 * are fixed for good the moment a payment is actually created
 * (`beforeValidate()`/`beforeSave()` derive the beneficiary/IBAN/BIC
 * snapshot and the QR code from them right then), and `Payment::metadata()`
 * already surfaces them read-only in the sidebar afterward — letting
 * `amount` stay editable past that point would desync it from a payment's
 * recorded status history.
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
        if (!$element instanceof Payment) return null;

        $html = $this->ownerFieldHtml($element, $static);

        if ($element->getIsUnpublishedDraft()) {
            $html .= $this->emailFieldHtml($element, $static) . $this->amountFieldHtml($element, $static);
        }

        return $html;
    }

    /**
     * @param Payment $element
     * @param bool $static
     * @return string
     */
    private function ownerFieldHtml(Payment $element, bool $static): string {
        $owner = $element->getOwner();

        return Cp::elementSelectFieldHtml([
            'label' => Craft::t('qr-payments', 'Account'),
            'instructions' => Craft::t('qr-payments', 'An existing Craft user to link this payment to, when the payer has a front-end account. Optional — a payment without one just runs on its email alone.'),
            'id' => 'userId',
            'name' => 'userId',
            'elementType' => User::class,
            'elements' => $owner ? [$owner] : [],
            'sources' => '*',
            'limit' => 1,
            'disabled' => $static,
            'errors' => $element->getErrors('userId'),
        ]);
    }

    /**
     * @param Payment $element
     * @param bool $static
     * @return string
     */
    private function emailFieldHtml(Payment $element, bool $static): string {
        return Cp::textFieldHtml([
            'label' => Craft::t('qr-payments', 'Email'),
            'instructions' => Craft::t('qr-payments', 'Where the payment QR code and status updates should be sent.'),
            'id' => 'email',
            'name' => 'email',
            'type' => 'email',
            'value' => $element->email,
            'required' => true,
            'disabled' => $static,
            'errors' => $element->getErrors('email'),
        ]);
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
