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

use PhpOffice\PhpSpreadsheet\IOFactory;

use RuntimeException;
use Throwable;

/**
 * Class XlsxTransactionsExportParser
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class XlsxTransactionsExportParser extends TransactionsExportParser {
    /**
     * @param string $path
     * @return array{headers: string[]|null, rows: string[][], amountColumn: int|null, referenceColumn: int|null}
     * @throws RuntimeException
     */
    public function parse(string $path): array {
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
        } catch (Throwable $e) {
            throw new RuntimeException('Could not read the uploaded file.', previous: $e);
        }

        $rows = $sheet->toArray(null, true, true, false);

        $rows = array_map(
            static fn(array $row): array => array_map(
                static fn(mixed $cell): string => trim((string) ($cell ?? '')),
                $row,
            ),
            $rows,
        );

        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => implode('', $row) !== '',
        ));

        return $this->finalize($rows);
    }
}
