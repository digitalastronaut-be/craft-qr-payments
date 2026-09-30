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

namespace digitalastronaut\craftqrpayments\widgets;

use Craft;
use craft\base\Widget;
use craft\elements\Entry;
use craft\helpers\StringHelper;

use digitalastronaut\craftqrpayments\elements\Payment;

/**
 * Class IngredientsCalculator
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0
 */
class IngredientsCalculator extends Widget {
    use DateRangeTrait;

    private const array COUNTED_STATUSES = [
        Payment::STATUS_PAID,
        Payment::STATUS_PENDING,
        Payment::STATUS_PARTIAL,
        Payment::STATUS_OVERPAID,
    ];

    /**
     * @inheritdoc
     */
    public static function displayName(): string {
        return Craft::t('qr-payments', 'Ingredients calculator');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool {
        return Craft::$app->getUser()->checkPermission('qr-payments:access-payments');
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        $rules = parent::defineRules();

        $rules[] = [['dateRange'], 'in', 'range' => static::dateRangeHandles()];
        $rules[] = [['startDate', 'endDate'], 'safe'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string {
        $period = $this->getPeriodWording();

        return $period === ''
            ? Craft::t('qr-payments', 'Ingredients calculator')
            : Craft::t('qr-payments', 'Ingredients {period}', ['period' => $period]);
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string {
        return Craft::$app->getView()->renderTemplate('qr-payments/widgets/ingredientsCalculator/body.twig', [
            'ingredients' => $this->calculateIngredients(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string {
        $id = 'ingredients-calculator' . StringHelper::randomString();
        $namespaceId = Craft::$app->getView()->namespaceInputId($id);

        return Craft::$app->getView()->renderTemplate('qr-payments/widgets/ingredientsCalculator/settings.twig', [
            'id' => $id,
            'namespaceId' => $namespaceId,
            'widget' => $this,
        ]);
    }

    /**
     * @return array{id: int, name: string, unit: string, amount: float}[] Sorted by ingredient name.
     */
    private function calculateIngredients(): array {
        [$start, $end] = $this->resolveDateRange();

        $query = Payment::find()
            ->status(self::COUNTED_STATUSES)
            ->with(['orderField.productField.recepieField.ingredientField']);

        if ($start !== null && $end !== null) {
            $query->dateCreated(['and', '>= ' . $start->toDateTimeString(), '< ' . $end->toDateTimeString()]);
        } elseif ($start !== null) {
            $query->dateCreated('>= ' . $start->toDateTimeString());
        }

        $totals = [];

        foreach ($query->all() as $payment) {
            foreach ($payment->orderField->all() as $orderLine) {
                $this->addOrderLine($totals, $orderLine);
            }
        }

        usort($totals, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $totals;
    }

    /**
     * @param array<int, array{id: int, name: string, unit: string, amount: float}> $totals
     * @param Entry $orderLine
     * @return void
     */
    private function addOrderLine(array &$totals, Entry $orderLine): void {
        $product = $orderLine->productField->one();
        $quantityOrdered = (float) $orderLine->amountField;

        if (!$product || $quantityOrdered <= 0) return;

        foreach ($product->recepieField->all() as $recepieLine) {
            $ingredient = $recepieLine->ingredientField->one();
            $amountPerUnit = (float) $recepieLine->amountField;

            if (!$ingredient || $amountPerUnit <= 0) continue;

            $totals[$ingredient->id] ??= [
                'id' => $ingredient->id,
                'name' => $ingredient->title,
                'unit' => $ingredient->unitField?->label ?? (string) $ingredient->unitField,
                'amount' => 0.0,
            ];

            $totals[$ingredient->id]['amount'] += $amountPerUnit * $quantityOrdered;
        }
    }
}
