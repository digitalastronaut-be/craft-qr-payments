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

use Carbon\Carbon;
use Carbon\CarbonInterface;

use DateTime;

/**
 * Class HumanDuration
 *
 * One shared one-segment, human-readable duration between now and a
 * given moment — `"2 weeks"`, `"340 days"`, etc. Used anywhere this
 * plugin shows "how long until/since this date" rather than the date
 * itself: `Subscription::validForLabel()`, `Payment::getDueInLabel()`
 * (shared by its "Expires in" column and the payments-summary digest
 * email), and the payments table on the subscriber edit page.
 *
 * Deliberately absolute (no "ago"/"from now" suffix) — every place this
 * is used already has a `status` column right next to it (`Active`/
 * `Expired`, `Paid`/`Pending`, ...) that already disambiguates remaining
 * vs. overdue, so the suffix would be redundant rather than clarifying.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
abstract class HumanDuration {
    /**
     * @param DateTime|null $date
     * @return string Empty string for a null `$date`, so a caller can drop it straight into `Html::encode()` without its own null-check.
     */
    public static function until(?DateTime $date): string {
        return $date !== null ? Carbon::instance($date)->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE) : '';
    }
}
