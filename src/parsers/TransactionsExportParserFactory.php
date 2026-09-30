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

/**
 * Class TransactionsExportParserFactory
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class TransactionsExportParserFactory {
    /**
     * @param string $extension The uploaded file’s original extension, used
     * to pick the right parser.
     * @return TransactionsExportParser
     */
    public static function create(string $extension): TransactionsExportParser {
        return match (strtolower($extension)) {
            'xlsx', 'xls' => new XlsxTransactionsExportParser(),
            default => new CsvTransactionsExportParser(),
        };
    }
}
