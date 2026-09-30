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

use Carbon\Carbon;

/**
 * Trait DateRangeTrait
 *
 * The date-range picker (`RANGE_*` handles, `resolveDateRange()`, period
 * wording) shared by every widget that filters by a date range — extracted
 * from `RecentPayments` so `IngredientsCalculator` gets identical behavior
 * via the same `_dateRangeField.twig` component instead of a second copy
 * of this logic.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0
 */
trait DateRangeTrait {
    public const string RANGE_ALL = 'all';
    public const string RANGE_TODAY = 'today';
    public const string RANGE_THIS_WEEK = 'thisWeek';
    public const string RANGE_THIS_MONTH = 'thisMonth';
    public const string RANGE_THIS_YEAR = 'thisYear';
    public const string RANGE_PAST_7_DAYS = 'past7Days';
    public const string RANGE_PAST_30_DAYS = 'past30Days';
    public const string RANGE_PAST_90_DAYS = 'past90Days';
    public const string RANGE_PAST_YEAR = 'pastYear';
    public const string RANGE_CUSTOM = 'custom';

    public mixed $dateRange = self::RANGE_ALL;
    public string $startDate = '';
    public string $endDate = '';

    /**
     * @return string[]
     */
    public static function dateRangeHandles(): array {
        return [
            self::RANGE_ALL,
            self::RANGE_TODAY,
            self::RANGE_THIS_WEEK,
            self::RANGE_THIS_MONTH,
            self::RANGE_THIS_YEAR,
            self::RANGE_PAST_7_DAYS,
            self::RANGE_PAST_30_DAYS,
            self::RANGE_PAST_90_DAYS,
            self::RANGE_PAST_YEAR,
            self::RANGE_CUSTOM,
        ];
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function resolveDateRange(): array {
        $now = Carbon::now('UTC');
        $tomorrow = $now->copy()->addDay()->startOfDay();

        return match ($this->dateRange) {
            self::RANGE_TODAY => [$now->copy()->startOfDay(), $tomorrow],
            self::RANGE_THIS_WEEK => [$now->copy()->startOfWeek(), $tomorrow],
            self::RANGE_THIS_MONTH => [$now->copy()->startOfMonth(), $tomorrow],
            self::RANGE_THIS_YEAR => [$now->copy()->startOfYear(), $tomorrow],
            self::RANGE_PAST_7_DAYS => [$now->copy()->subDays(7), null],
            self::RANGE_PAST_30_DAYS => [$now->copy()->subDays(30), null],
            self::RANGE_PAST_90_DAYS => [$now->copy()->subDays(90), null],
            self::RANGE_PAST_YEAR => [$now->copy()->subYear(), null],
            self::RANGE_CUSTOM => [
                $this->startDate !== '' ? Carbon::parse($this->startDate, 'UTC') : null,
                $this->endDate !== '' ? Carbon::parse($this->endDate, 'UTC')->addDay() : null,
            ],
            default => [null, null],
        };
    }

    /**
     * @return string
     */
    private function getPeriodWording(): string {
        return match ($this->dateRange) {
            self::RANGE_TODAY => Craft::t('qr-payments', 'today'),
            self::RANGE_THIS_WEEK => Craft::t('qr-payments', 'this week'),
            self::RANGE_THIS_MONTH => Craft::t('qr-payments', 'this month'),
            self::RANGE_THIS_YEAR => Craft::t('qr-payments', 'this year'),
            self::RANGE_PAST_7_DAYS => Craft::t('qr-payments', 'last 7 days'),
            self::RANGE_PAST_30_DAYS => Craft::t('qr-payments', 'last 30 days'),
            self::RANGE_PAST_90_DAYS => Craft::t('qr-payments', 'last 90 days'),
            self::RANGE_PAST_YEAR => Craft::t('qr-payments', 'last year'),
            self::RANGE_CUSTOM => $this->getCustomPeriodWording(),
            default => '',
        };
    }

    /**
     * @return string
     */
    private function getCustomPeriodWording(): string {
        if ($this->startDate === '' && $this->endDate === '') return '';

        $format = fn(string $date) => Carbon::parse($date, 'UTC')->locale(Craft::$app->language)->translatedFormat('j F Y');

        if ($this->startDate !== '' && $this->endDate !== '') {
            return $format($this->startDate) . ' - ' . $format($this->endDate);
        }

        return $this->startDate !== ''
            ? Craft::t('qr-payments', 'since {date}', ['date' => $format($this->startDate)])
            : Craft::t('qr-payments', 'until {date}', ['date' => $format($this->endDate)]);
    }
}
