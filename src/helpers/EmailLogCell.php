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
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;

use Carbon\Carbon;

/**
 * Class EmailLogCell
 *
 * Shared by `StatusHistoryField` and `SubscriptionStatusHistoryField`: the
 * "Email" column each of those tables adds next to its real/synthesized
 * rows — a sent/failed badge plus a resend button, matched to the row via
 * `historyEntryId` against `EmailLogService::getForPayment()`/
 * `getForSubscription()`'s result.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.9.0-beta
 */
abstract class EmailLogCell {
    /**
     * @param string|null $historyEntryId The status-history row's own `id`, or null for a row that predates this column (migrated data) or is synthesized (never has one).
     * @param array<string, array> $emailLog `EmailLogService::getForPayment()`/`getForSubscription()`'s result, indexed by `historyEntryId`.
     * @return string
     */
    public static function html(?string $historyEntryId, array $emailLog): string {
        $row = $historyEntryId !== null ? ($emailLog[$historyEntryId] ?? null) : null;

        if ($row === null) return '-';

        $sent = $row['status'] === 'sent';

        $date = DateTimeHelper::toDateTime($row['dateCreated']);
        $title = $sent
            ? Craft::t('qr-payments', 'Sent to {recipient}{when}', [
                'recipient' => $row['recipient'],
                'when' => $date ? ' ' . Carbon::instance($date)->diffForHumans() : '',
            ])
            : Craft::t('qr-payments', 'Failed to send to {recipient}: {error}', [
                'recipient' => $row['recipient'],
                'error' => $row['error'] ?: Craft::t('qr-payments', 'unknown error'),
            ]);

        $badge = Html::tag('span', Cp::statusLabelHtml([
            'color' => $sent ? Color::Green : Color::Red,
            'label' => $sent ? Craft::t('qr-payments', 'Sent') : Craft::t('qr-payments', 'Failed'),
        ]), ['title' => $title]);

        $resend = Html::tag('button', Craft::t('qr-payments', 'Resend'), [
            'type' => 'button',
            'class' => ['btn', 'small', 'formsubmit'],
            'data' => [
                'action' => 'qr-payments/email-log/resend',
                'params' => ['logId' => $row['id']],
                'confirm' => Craft::t('qr-payments', 'Resend this email to {recipient}?', ['recipient' => $row['recipient']]),
            ],
        ]);

        // Same hairline-outline treatment `.lightswitch-inner-container`
        // gets around its on/off labels — a thin `--border-hairline` box,
        // `--radius-sm` corners — around the badge and button together,
        // so the pair reads as one contained control rather than two
        // loose elements floating in the cell.
        return Html::tag('div', $badge . $resend, [
            'class' => ['flex', 'flex-nowrap', 'gap-m'],
            'style' => 'align-items: center; border: 1px solid var(--border-hairline); border-radius: var(--radius-sm); padding: 8px 12px; width: fit-content;',
        ]);
    }
}
