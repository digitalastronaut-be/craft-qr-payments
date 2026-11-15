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
use craft\helpers\MoneyHelper;

use digitalastronaut\craftqrpayments\elements\Subscription;
use digitalastronaut\craftqrpayments\models\SubscriptionType;
use digitalastronaut\craftqrpayments\QrPayments;

use yii\base\NotSupportedException;

/**
 * Class SubscriptionFieldsField
 *
 * Renders every editable native attribute of a `Subscription` — owner,
 * email, subscription type, the amount/interval overrides, and the dates
 * — as one block in the main content area of the native element edit
 * screen. `Subscription` doesn't support custom fields, so this is its
 * *only* field-layout element: the "code-only field layout" pattern
 * (plugin-owned shape, no designer route), the same way `TitleField`/
 * other native fields work in Craft core.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.7.0-beta
 */
class SubscriptionFieldsField extends FieldLayoutElement {
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

        return
            $this->ownerFieldHtml($element, $static) .
            $this->emailFieldHtml($element, $static) .
            $this->subscriptionTypeFieldHtml($element, $static) .
            $this->amountFieldHtml($element, $static) .
            $this->intervalFieldHtml($element, $static) .
            $this->startedAtFieldHtml($element, $static) .
            $this->nextDueDateFieldHtml($element, $static);
    }

    /**
     * @param Subscription $element
     * @param bool $static
     * @return string
     */
    private function ownerFieldHtml(Subscription $element, bool $static): string {
        $owner = $element->getOwner();

        return Cp::elementSelectFieldHtml([
            'label' => Craft::t('qr-payments', 'Account'),
            'instructions' => Craft::t('qr-payments', 'An existing Craft user to key access control off of, when this site has front-end accounts. Optional — a subscriber without one runs on their email alone.'),
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
     * @param Subscription $element
     * @param bool $static
     * @return string
     */
    private function emailFieldHtml(Subscription $element, bool $static): string {
        return Cp::textFieldHtml([
            'label' => Craft::t('qr-payments', 'Email'),
            'instructions' => Craft::t('qr-payments', 'Where reminders and the subscription’s payments are sent. Always required, even when an account is also set — see the “Account” field above.'),
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
     * @param Subscription $element
     * @param bool $static
     * @return string
     */
    private function subscriptionTypeFieldHtml(Subscription $element, bool $static): string {
        $options = array_map(
            static fn(SubscriptionType $type) => ['label' => $type->name, 'value' => $type->id],
            QrPayments::getInstance()->getSubscriptionTypes()->getAllSubscriptionTypes(),
        );

        return Cp::selectFieldHtml([
            'label' => Craft::t('qr-payments', 'Subscription type'),
            'instructions' => Craft::t('qr-payments', 'Which plan this subscriber is enrolled in — its price, interval, and payment type come from here unless overridden below.'),
            'id' => 'subscriptionTypeId',
            'name' => 'subscriptionTypeId',
            'options' => $options,
            'value' => $element->subscriptionTypeId,
            'required' => true,
            'disabled' => $static,
            'errors' => $element->getErrors('subscriptionTypeId'),
        ]);
    }

    /**
     * @param Subscription $element
     * @param bool $static
     * @return string
     */
    private function amountFieldHtml(Subscription $element, bool $static): string {
        $amount = $element->getAmount();
        $tip = null;

        if ($element->id && $element->getSubscriptionType()) {
            $tip = Craft::t('qr-payments', 'Currently charged: {amount}', [
                'amount' => MoneyHelper::toString($element->getEffectiveAmount()),
            ]);
        }

        return Cp::moneyFieldHtml([
            'label' => Craft::t('qr-payments', 'Amount override'),
            'instructions' => Craft::t('qr-payments', 'Leave blank to follow the subscription type’s current amount — it’ll update automatically if that changes. Set this to freeze this one subscriber at a specific rate.'),
            'tip' => $tip,
            'id' => 'amount',
            'name' => 'amount',
            'currency' => 'EUR',
            'currencyLabel' => '(EUR) €',
            'value' => $amount ? $amount->getAmount() / 100 : null,
            'disabled' => $static,
            'errors' => $element->getErrors('amount'),
        ]);
    }

    /**
     * @param Subscription $element
     * @param bool $static
     * @return string
     */
    private function intervalFieldHtml(Subscription $element, bool $static): string {
        $options = [['label' => Craft::t('qr-payments', 'Follow the subscription type'), 'value' => '']];

        foreach (SubscriptionType::intervals() as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }

        $type = $element->getSubscriptionType();
        $tip = null;

        if ($element->id && $type) {
            $effective = $element->getEffectiveInterval();
            $tip = Craft::t('qr-payments', 'Currently billed: {interval}', [
                'interval' => SubscriptionType::intervals()[$effective] ?? $effective,
            ]);
        }

        return Cp::selectizeFieldHtml([
            'label' => Craft::t('qr-payments', 'Interval override'),
            'instructions' => Craft::t('qr-payments', 'Leave as “Follow the subscription type” to use its current interval — it’ll update automatically if that changes. Set this to freeze this one subscriber at a specific cadence.'),
            'tip' => $tip,
            'id' => 'interval',
            'name' => 'interval',
            'options' => $options,
            'value' => $element->interval ?? '',
            'disabled' => $static,
            'errors' => $element->getErrors('interval'),
        ]);
    }

    /**
     * @param Subscription $element
     * @param bool $static
     * @return string
     */
    private function startedAtFieldHtml(Subscription $element, bool $static): string {
        return Cp::dateTimeFieldHtml([
            'label' => Craft::t('qr-payments', 'Started at'),
            'id' => 'startedAt',
            'name' => 'startedAt',
            'value' => $element->startedAt,
            'required' => true,
            'disabled' => $static,
            'errors' => $element->getErrors('startedAt'),
        ]);
    }

    /**
     * @param Subscription $element
     * @param bool $static
     * @return string
     */
    private function nextDueDateFieldHtml(Subscription $element, bool $static): string {
        return Cp::dateTimeFieldHtml([
            'label' => Craft::t('qr-payments', 'Next due date'),
            'instructions' => Craft::t('qr-payments', 'When the next cycle’s payment is created. Left blank, the recurrence runner never picks this subscription up.'),
            'id' => 'nextDueDate',
            'name' => 'nextDueDate',
            'value' => $element->nextDueDate,
            'disabled' => $static,
            'errors' => $element->getErrors('nextDueDate'),
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function actionMenuItems(?ElementInterface $element = null, bool $static = false): array {
        return [];
    }
}
