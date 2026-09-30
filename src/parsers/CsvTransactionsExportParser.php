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

use RuntimeException;
use SplFileObject;

/**
 * Class CsvTransactionsExportParser
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.6.0-beta
 */
class CsvTransactionsExportParser extends TransactionsExportParser {
    private const array DELIMITER_CANDIDATES = [',', ';', "\t"];

    /**
     * @param string $path
     * @return array{headers: string[]|null, rows: string[][], amountColumn: int|null, referenceColumn: int|null}
     * @throws RuntimeException 
     */
    public function parse(string $path): array {
        $raw = file_get_contents($path);

        if ($raw === false) throw new RuntimeException('Could not read the uploaded file.');

        $raw = $this->normalizeEncoding($raw);
        $delimiter = $this->sniffDelimiter($raw);
        $rows = $this->readRows($raw, $delimiter);

        return $this->finalize($rows);
    }

    /**
     * @param string $raw
     * @return string
     */
    private function normalizeEncoding(string $raw): string {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param string $raw
     * @return string
     */
    private function sniffDelimiter(string $raw): string {
        $firstLine = strtok($raw, "\r\n") ?: '';

        $best = ',';
        $bestCount = -1;

        foreach (self::DELIMITER_CANDIDATES as $candidate) {
            $count = substr_count($firstLine, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param string $raw
     * @param string $delimiter
     * @return string[][]
     */
    private function readRows(string $raw, string $delimiter): array {
        $tempPath = tempnam(sys_get_temp_dir(), 'qrp-import-');
        file_put_contents($tempPath, $raw);

        try {
            $file = new SplFileObject($tempPath);
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
            $file->setCsvControl($delimiter);

            $rows = [];

            foreach ($file as $row) {
                if ($row === [null] || $row === false) continue;
                $rows[] = $row;
            }

            return $rows;
        } finally {
            unlink($tempPath);
        }
    }
}
