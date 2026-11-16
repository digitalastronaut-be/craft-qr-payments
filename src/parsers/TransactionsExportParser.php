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

namespace digitalastronaut\craftqrpayments\parsers;

use Craft;
use craft\helpers\Cp;

use digitalastronaut\craftqrpayments\elements\Payment;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;

use Throwable;

/**
 * Class TransactionsExportParser
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
abstract class TransactionsExportParser {
    private const string REFERENCE_PATTERN = '/^RF\d{2}[A-Z0-9]+$/i';
    private const string AMOUNT_PATTERN = '/^[+-]?\d+([.,]\d{3})*[.,]\d{2}$/';
    private const string DATE_PATTERN = '#^\d{1,4}[-/.]\d{1,2}[-/.]\d{1,4}$#';

    private const array REFERENCE_HEADER_KEYWORDS = [
        'reference', 'referentie', 'référence', 'mededeling', 'communication',
        'communicatie', 'omschrijving', 'description',
    ];

    private const array AMOUNT_HEADER_KEYWORDS = [
        'amount', 'bedrag', 'montant', 'value', 'waarde', 'credit', 'debit',
    ];

    private const array DATE_HEADER_KEYWORDS = [
        'date', 'datum', 'valutadatum', 'boekdatum', 'verrichtingsdatum',
    ];

    /**
     * @param string $path
     * @return array{headers: string[]|null, rows: string[][], amountColumn: int|null, referenceColumn: int|null, dateColumn: int|null}
     */
    abstract public function parse(string $path): array;

    /**
     * @param string[][] $rows
     * @return array{headers: string[]|null, rows: string[][], amountColumn: int|null, referenceColumn: int|null, dateColumn: int|null}
     */
    protected function finalize(array $rows): array {
        $parsed = $this->splitHeaderRow($rows);

        return $parsed + [
            'amountColumn' => $this->detectAmountColumn($parsed['headers'], $parsed['rows']),
            'referenceColumn' => $this->detectReferenceColumn($parsed['headers'], $parsed['rows']),
            'dateColumn' => $this->detectDateColumn($parsed['headers'], $parsed['rows']),
        ];
    }

    /**
     * @param string[][] $rows
     * @param int|null $referenceColumn
     * @param int|null $amountColumn
     * @param int|null $dateColumn
     * @return array<int, array{
     *  payment: Payment|null, 
     *  status: string|null, 
     *  netPaidNow: Money|null, 
     *  alreadyProcessed: bool, 
     *  suggestedAction: string|null, 
     *  statusAfter: string|null, 
     *  netPaidAfter: Money|null, 
     *  actionType: string|null, 
     *  actionAmount: Money|null}>
     */
    public static function matchPayments(array $rows, ?int $referenceColumn, ?int $amountColumn, ?int $dateColumn): array {
        $payments = self::findMatchingPayments($rows, $referenceColumn);
        $working = [];
        $results = [];

        foreach ($rows as $index => $row) {
            $payment = $payments[$index];

            if ($payment === null) {
                $results[] = self::emptyRowResult();
                continue;
            }

            $effective = $working[$payment->id] ?? $payment;

            $reference = $referenceColumn !== null ? self::normalizeReference((string) ($row[$referenceColumn] ?? '')) : '';
            $rowAmount = $amountColumn !== null ? self::parseAmount($row[$amountColumn] ?? null) : null;
            $rowDate = self::rowDateValue($row, $dateColumn);

            $evaluation = self::evaluateRow($effective, $reference, $rowAmount, $rowDate);
            $suggestion = $evaluation['suggestion'];

            $results[] = [
                'payment' => $payment,
                'status' => self::statusChipHtml($effective->getStatus()),
                'netPaidNow' => $effective->netPaidAmount(),
                'alreadyProcessed' => $evaluation['alreadyProcessed'],
                'suggestedAction' => $suggestion['label'] ?? null,
                'statusAfter' => $suggestion['statusAfter'] ?? null,
                'netPaidAfter' => $suggestion['netPaidAfter'] ?? null,
                'actionType' => $suggestion['type'] ?? null,
                'actionAmount' => $suggestion['amount'] ?? null,
            ];

            if ($suggestion !== null) {
                $advanced = clone $effective;
                $advanced->pushStatusHistoryEntry($suggestion['type'], $suggestion['amount'], null, null, $evaluation['fingerprint']);
                $working[$payment->id] = $advanced;
            }
        }

        return $results;
    }

    /**
     * @param string[][] $rows
     * @param int|null $referenceColumn
     * @param int|null $amountColumn
     * @param int|null $dateColumn
     * @param int|null $userId
     * @return array{ processed: int, skipped: int, failed: int }
     */
    public static function applySuggestedActions(array $rows, ?int $referenceColumn, ?int $amountColumn, ?int $dateColumn, ?int $userId): array {
        $payments = self::findMatchingPayments($rows, $referenceColumn);
        $touched = [];
        $skipped = 0;

        foreach ($rows as $index => $row) {
            $payment = $payments[$index];

            if ($payment === null) continue;

            $reference = $referenceColumn !== null ? self::normalizeReference((string) ($row[$referenceColumn] ?? '')) : '';
            $rowAmount = $amountColumn !== null ? self::parseAmount($row[$amountColumn] ?? null) : null;
            $rowDate = self::rowDateValue($row, $dateColumn);

            $evaluation = self::evaluateRow($payment, $reference, $rowAmount, $rowDate);

            if ($evaluation['alreadyProcessed']) {
                $skipped++;
                continue;
            }

            $suggestion = $evaluation['suggestion'];

            if ($suggestion === null) continue;

            $payment->pushStatusHistoryEntry($suggestion['type'], $suggestion['amount'], $userId, null, $evaluation['fingerprint']);
            $touched[$payment->id] = $payment;
        }

        $processed = 0;
        $failed = 0;

        foreach ($touched as $payment) {
            if (Craft::$app->getElements()->saveElement($payment)) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @param string|null $value
     * @return Money|null
     */
    public static function parseAmount(?string $value): ?Money {
        if ($value === null) return null;

        $value = trim($value);

        if ($value === '' || !preg_match(self::AMOUNT_PATTERN, $value)) return null;

        $integerPart = preg_replace('/[.,]/', '', substr($value, 0, -3));
        $decimalPart = substr($value, -2);

        try {
            return (new DecimalMoneyParser(new ISOCurrencies()))->parse(
                "{$integerPart}.{$decimalPart}",
                new Currency(Payment::CURRENCY),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param string[][] $rows
     * @return array{headers: string[]|null, rows: string[][]}
     */
    private function splitHeaderRow(array $rows): array {
        $headers = null;

        if (!empty($rows) && $this->looksLikeHeaderRow($rows[0])) $headers = array_shift($rows);

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * @param string[] $row
     * @return bool
     */
    private function looksLikeHeaderRow(array $row): bool {
        foreach ($row as $cell) {
            $cell = trim((string) $cell);

            if ($cell === '') continue;
            if (preg_match('/^-?[\d.,]+$/', $cell)) return false;
            if (preg_match(self::DATE_PATTERN, $cell)) return false;
        }

        return true;
    }

    /**
     * @param string[]|null $headers
     * @param string[][] $rows
     * @return int|null
     */
    private function detectReferenceColumn(?array $headers, array $rows): ?int {
        return $this->columnWithMostMatches($rows, self::REFERENCE_PATTERN, stripSpaces: true)
            ?? $this->columnMatchingHeaderKeyword($headers, self::REFERENCE_HEADER_KEYWORDS);
    }

    /**
     * @param string[]|null $headers
     * @param string[][] $rows
     * @return int|null
     */
    private function detectAmountColumn(?array $headers, array $rows): ?int {
        return $this->columnMatchingHeaderKeyword($headers, self::AMOUNT_HEADER_KEYWORDS)
            ?? $this->columnWithMostMatches($rows, self::AMOUNT_PATTERN, stripSpaces: false, minRatio: 0.8);
    }

    /**
     * @param string[]|null $headers
     * @param string[][] $rows
     * @return int|null
     */
    private function detectDateColumn(?array $headers, array $rows): ?int {
        return $this->columnMatchingHeaderKeyword($headers, self::DATE_HEADER_KEYWORDS)
            ?? $this->columnWithMostMatches($rows, self::DATE_PATTERN, stripSpaces: false, minRatio: 0.8);
    }

    /**
     * @param string[]|null $headers
     * @param string[] $keywords
     * @return int|null
     */
    private function columnMatchingHeaderKeyword(?array $headers, array $keywords): ?int {
        if ($headers === null) return null;

        foreach ($headers as $index => $header) {
            $header = mb_strtolower(trim((string) $header));

            foreach ($keywords as $keyword) {
                if (str_contains($header, $keyword)) return $index;
            }
        }

        return null;
    }

    /**
     * @param string[][] $rows
     * @param string $pattern
     * @param bool $stripSpaces
     * @param float $minRatio
     * @return int|null
     */
    private function columnWithMostMatches(array $rows, string $pattern, bool $stripSpaces, float $minRatio = 0.0): ?int {
        if (empty($rows)) return null;

        $columnCount = max(array_map('count', $rows));

        $bestColumn = null;
        $bestRatio = 0.0;

        for ($index = 0; $index < $columnCount; $index++) {
            $seen = 0;
            $matched = 0;

            foreach ($rows as $row) {
                $value = trim((string) ($row[$index] ?? ''));

                if ($value === '') continue;

                $seen++;

                if (preg_match($pattern, $stripSpaces ? preg_replace('/\s+/', '', $value) : $value)) $matched++;
            }

            if ($seen === 0) continue;

            $ratio = $matched / $seen;

            if ($matched > 0 && $ratio >= $minRatio && $ratio > $bestRatio) {
                $bestRatio = $ratio;
                $bestColumn = $index;
            }
        }

        return $bestColumn;
    }

    /**
     * @param string[][] $rows
     * @param int|null $referenceColumn
     * @return array<int, Payment|null>
     */
    private static function findMatchingPayments(array $rows, ?int $referenceColumn): array {
        if ($referenceColumn === null) {
            return array_fill(0, \count($rows), null);
        }

        $references = array_map(
            fn(array $row) => self::normalizeReference((string) ($row[$referenceColumn] ?? '')),
            $rows,
        );

        $lookup = array_filter($references, fn(string $reference) => $reference !== '');

        $payments = empty($lookup) ? [] : Payment::find()
            ->remittanceReference(array_values(array_unique($lookup)))
            ->status(null)
            ->indexBy('remittanceReference')
            ->all();

        return array_map(fn(string $reference) => $payments[$reference] ?? null, $references);
    }

    /**
     * @param string $reference
     * @return string
     */
    private static function normalizeReference(string $reference): string {
        return preg_replace('/\s+/', '', trim($reference)) ?? '';
    }

    /**
     * @param string[] $row
     * @param int|null $dateColumn
     * @return string|null
     */
    private static function rowDateValue(array $row, ?int $dateColumn): ?string {
        if ($dateColumn === null) return null;

        $value = trim((string) ($row[$dateColumn] ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param Payment $payment The state to evaluate against — a real
     * Payment when applying, or a running clone when only previewing.
     * @param string $reference
     * @param Money|null $rowAmount
     * @param string|null $rowDate
     * @return array{fingerprint: string|null, alreadyProcessed: bool, suggestion: array{label: string, statusAfter: string, netPaidAfter: Money, type: string, amount: Money}|null}
     */
    private static function evaluateRow(Payment $payment, string $reference, ?Money $rowAmount, ?string $rowDate): array {
        if ($rowAmount === null) {
            return ['fingerprint' => null, 'alreadyProcessed' => false, 'suggestion' => null];
        }

        $fingerprint = self::rowFingerprint($reference, $rowAmount, $rowDate);

        if (self::isAlreadyProcessed($payment, $fingerprint)) {
            return ['fingerprint' => $fingerprint, 'alreadyProcessed' => true, 'suggestion' => null];
        }

        return ['fingerprint' => $fingerprint, 'alreadyProcessed' => false, 'suggestion' => self::suggestAction($payment, $rowAmount)];
    }

    /**
     * @param string $reference
     * @param Money $amount
     * @param string|null $date
     * @return string
     */
    private static function rowFingerprint(string $reference, Money $amount, ?string $date): string {
        return hash('sha256', implode('|', [
            $reference,
            $amount->getAmount(),
            $amount->getCurrency()->getCode(),
            $date ?? '',
        ]));
    }

    /**
     * @param Payment $payment
     * @param string $fingerprint
     * @return bool
     */
    private static function isAlreadyProcessed(Payment $payment, string $fingerprint): bool {
        foreach ($payment->getStatusHistory() as $entry) {
            if (($entry['source'] ?? null) === $fingerprint) return true;
        }

        return false;
    }

    /**
     * @return array{payment: null, status: null, netPaidNow: null, alreadyProcessed: false, suggestedAction: null, statusAfter: null, netPaidAfter: null, actionType: null, actionAmount: null}
     */
    private static function emptyRowResult(): array {
        return [
            'payment' => null,
            'status' => null,
            'netPaidNow' => null,
            'alreadyProcessed' => false,
            'suggestedAction' => null,
            'statusAfter' => null,
            'netPaidAfter' => null,
            'actionType' => null,
            'actionAmount' => null,
        ];
    }

    /**
     * Decides what to suggest doing with a row’s amount against its
     * matched payment, based on the payment’s current status:
     *
     * - Cancelled or already settled (paid/refunded): only suggests a
     *   refund, and only if the row amount wouldn’t refund more than was
     *   actually paid.
     * - Anything still open (requested/partial/overpaid/expired): suggests
     *   recording it as a payment — “Mark as paid” specifically when
     *   nothing’s been paid yet and the row amount exactly covers the
     *   outstanding balance, “Add payment” otherwise.
     *
     * @param Payment $payment
     * @param Money $rowAmount
     * @return array{label: string, statusAfter: string, netPaidAfter: Money, type: string, amount: Money}|null
     */
    private static function suggestAction(Payment $payment, Money $rowAmount): ?array {
        if (!$rowAmount->isPositive()) return null;

        $status = $payment->getStatus();
        $netPaid = $payment->netPaidAmount();

        if ($status === Payment::STATUS_CANCELLED || \in_array($status, [Payment::STATUS_PAID, Payment::STATUS_REFUNDED], true)) {
            if (!$netPaid->isPositive() || $rowAmount->greaterThan($netPaid)) return null;

            return self::simulate($payment, Payment::HISTORY_TYPE_REFUND, $rowAmount, Craft::t('qr-payments', 'Refund'));
        }

        $hasPayment = false;

        foreach ($payment->getStatusHistory() as $entry) {
            if (($entry['type'] ?? null) === Payment::HISTORY_TYPE_PAYMENT) {
                $hasPayment = true;
                break;
            }
        }

        $total = $payment->getAmount();
        $remaining = $total !== null ? $total->subtract($netPaid) : null;
        $isFullPayment = !$hasPayment && $remaining !== null && $rowAmount->equals($remaining);

        return self::simulate(
            $payment,
            Payment::HISTORY_TYPE_PAYMENT,
            $rowAmount,
            $isFullPayment ? Craft::t('qr-payments', 'Mark as paid') : Craft::t('qr-payments', 'Add payment'),
        );
    }

    /**
     * Clones the payment, pushes a hypothetical history entry onto the
     * clone, and reads back what its status would become — reusing
     * Payment’s own status computation instead of re-deriving those rules
     * here, so this can never drift out of sync with the real thing.
     *
     * @param Payment $payment
     * @param string $type
     * @param Money $amount
     * @param string $label
     * @return array{label: string, statusAfter: string, netPaidAfter: Money, type: string, amount: Money}
     */
    private static function simulate(Payment $payment, string $type, Money $amount, string $label): array {
        $hypothetical = clone $payment;
        $hypothetical->pushStatusHistoryEntry($type, $amount, null);

        return [
            'label' => $label,
            'statusAfter' => self::statusChipHtml($hypothetical->computeStatus()),
            'netPaidAfter' => $hypothetical->netPaidAmount(),
            'type' => $type,
            'amount' => $amount,
        ];
    }

    /**
     * Renders the same colored status chip Craft uses everywhere else in
     * the CP, instead of plain status text.
     *
     * @param string|null $status
     * @return string|null
     */
    private static function statusChipHtml(?string $status): ?string {
        if ($status === null) return null;

        return Cp::statusLabelHtml(Payment::statuses()[$status] ?? ['label' => $status]);
    }
}
