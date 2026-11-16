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

namespace digitalastronaut\craftqrpayments\helpers;

use Craft;

use DateTime;

/**
 * Class CpDateFormatter
 *
 * One shared date format for every CP-facing, plugin-owned date this
 * plugin renders (status history, due dates, "confirmed at", "ended
 * at", ...) — explicit (`15 November 2026 14:05:47`) rather than
 * Craft's own locale-dependent `short` format, which renders as ambiguous
 * slash-separated digits depending on the install's locale. Deliberately
 * not applied to a native Craft attribute (`dateCreated`/`dateUpdated`):
 * those stay in whatever format the rest of the CP uses for every other
 * element type, so this plugin doesn't look inconsistent with itself.
 *
 * Still routed through `Craft::$app->getFormatter()` (not a plain
 * `DateTime::format()` call) so the result still converts to the CP
 * user's configured display timezone first — only the pattern itself is
 * fixed, not the timezone handling.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
abstract class CpDateFormatter {
    /** @var string A `php:`-prefixed pattern so Yii's formatter treats it as a literal PHP `date()` format rather than an ICU pattern (where, e.g., `F` means something else entirely). */
    public const string FORMAT = 'php:d F Y H:i:s';

    /**
     * @param DateTime|null $date
     * @return string Empty string for a null `$date`, so a caller can drop it straight into `Html::encode()` without its own null-check.
     */
    public static function format(?DateTime $date): string {
        return $date !== null ? Craft::$app->getFormatter()->asDatetime($date, self::FORMAT) : '';
    }
}
