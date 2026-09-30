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
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\elements\db\PaymentQuery;

/**
 * Class RecentPayments
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class RecentPayments extends Widget {
    use DateRangeTrait;

    public const string CHART_DISPLAY_BOTH = 'both';
    public const string CHART_DISPLAY_COUNTS = 'counts';
    public const string CHART_DISPLAY_AMOUNTS = 'amounts';

    public const string SORT_RECENT = 'recent';
    public const string SORT_AMOUNT_DESC = 'amountDesc';
    public const string SORT_AMOUNT_ASC = 'amountAsc';

    private const array CHART_COLORS = [
        'orange' => '251, 146, 60',
        'teal' => '17, 166, 151',
        'red' => '220, 38, 38',
        'gray' => '154, 165, 177',
        'purple' => '168, 85, 247',
    ];

    public array|string $statuses = [];
    public int $limit = 10;
    public string $chartDisplay = self::CHART_DISPLAY_BOTH;
    public string $sort = self::SORT_RECENT;

    /**
     * @inheritdoc
     */
    public static function displayName(): string {
        return Craft::t('qr-payments', 'Recent payments');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string {
        return '@digitalastronaut/craftqrpayments/web/icons/qr-code-alt.svg';
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool {
        return Craft::$app->getUser()->checkPermission('qr-payments:access-payments');
    }

    /**
     * @return string[]
     */
    public static function chartDisplayHandles(): array {
        return [
            self::CHART_DISPLAY_BOTH,
            self::CHART_DISPLAY_COUNTS,
            self::CHART_DISPLAY_AMOUNTS,
        ];
    }

    /**
     * @return array{label: string, value: string}[]
     */
    public static function chartDisplayOptions(): array {
        return [
            ['label' => Craft::t('qr-payments', 'Counts and amounts'), 'value' => self::CHART_DISPLAY_BOTH],
            ['label' => Craft::t('qr-payments', 'Counts only'), 'value' => self::CHART_DISPLAY_COUNTS],
            ['label' => Craft::t('qr-payments', 'Amounts only'), 'value' => self::CHART_DISPLAY_AMOUNTS],
        ];
    }

    /**
     * @return string[]
     */
    public static function sortHandles(): array {
        return [
            self::SORT_RECENT,
            self::SORT_AMOUNT_DESC,
            self::SORT_AMOUNT_ASC,
        ];
    }

    /**
     * @return array{label: string, value: string}[]
     */
    public static function sortOptions(): array {
        return [
            ['label' => Craft::t('qr-payments', 'Recent'), 'value' => self::SORT_RECENT],
            ['label' => Craft::t('qr-payments', 'Amount (High - Low)'), 'value' => self::SORT_AMOUNT_DESC],
            ['label' => Craft::t('qr-payments', 'Amount (Low - High)'), 'value' => self::SORT_AMOUNT_ASC],
        ];
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        $rules = parent::defineRules();

        $rules[] = [['dateRange'], 'in', 'range' => static::dateRangeHandles()];
        $rules[] = [['chartDisplay'], 'in', 'range' => static::chartDisplayHandles()];
        $rules[] = [['sort'], 'in', 'range' => static::sortHandles()];
        $rules[] = [['limit'], 'integer', 'min' => 0, 'max' => 20];
        $rules[] = [['startDate', 'endDate'], 'safe'];
        $rules[] = [['statuses'], 'validateStatuses'];

        return $rules;
    }

    /**
     * @return void
     */
    public function validateStatuses(): void {
        $validStatuses = array_keys(Payment::statuses());

        foreach ($this->getStatusesArray() as $status) {
            if (!\in_array($status, $validStatuses, true)) {
                $this->addError('statuses', Craft::t('qr-payments', 'Invalid status: “{status}”.', ['status' => $status]));
                return;
            }
        }
    }

    /**
     * @return string[]
     */
    public function getStatusesArray(): array {
        if (is_array($this->statuses)) return array_values(array_filter($this->statuses));

        return $this->statuses !== '' ? [$this->statuses] : [];
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string {
        $period = $this->getPeriodWording();

        return $period === ''
            ? Craft::t('qr-payments', 'Payments')
            : Craft::t('qr-payments', 'Payments {period}', ['period' => $period]);
    }

    /**
     * @inheritdoc
     */
    public function getSubtitle(): ?string {
        $counts = $this->getStatusCounts();

        if (empty($counts)) return Craft::t('qr-payments', 'No payments');

        $parts = [];

        foreach ($counts as $status => $count) {
            $parts[] = Craft::t('qr-payments', '{count} {status}', [
                'count' => $count,
                'status' => mb_strtolower(Payment::statuses()[$status]['label']),
            ]);
        }

        return implode(', ', $parts);
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string {
        return Craft::$app->getView()->renderTemplate('qr-payments/widgets/recentPayments/body.twig', [
            'payments' => $this->getPayments(),
            'statuses' => Payment::statuses(),
            'indexUrl' => UrlHelper::cpUrl('qr-payments/payments'),
            'chartId' => 'recent-payments-chart' . StringHelper::randomString(),
            'chartData' => $this->getChartData(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string {
        $id = 'recent-payments' . StringHelper::randomString();
        $namespaceId = Craft::$app->getView()->namespaceInputId($id);

        return Craft::$app->getView()->renderTemplate('qr-payments/widgets/recentPayments/settings.twig', [
            'id' => $id,
            'namespaceId' => $namespaceId,
            'widget' => $this,
            'statusOptions' => array_map(
                fn(array $status, string $value) => ['label' => $status['label'], 'value' => $value],
                Payment::statuses(),
                array_keys(Payment::statuses()),
            ),
            'chartDisplayOptions' => static::chartDisplayOptions(),
            'sortOptions' => static::sortOptions(),
        ]);
    }

    /**
     * @return Payment[]
     */
    private function getPayments(): array {
        return $this->baseQuery()->limit($this->limit)->all();
    }

    /**
     * @return array<string, int>
     */
    private function getStatusCounts(): array {
        $configuredStatuses = $this->getStatusesArray() ?: array_keys(Payment::statuses());
        $dbCounts = $this->baseQuery()->statusCounts();

        $counts = [];

        foreach (array_keys(Payment::statuses()) as $status) {
            if (in_array($status, $configuredStatuses, true)) $counts[$status] = $dbCounts[$status] ?? 0;
        }

        return array_filter($counts);
    }

    /**
     * @return array{labels: string[], datasets: array}
     */
    private function getChartData(): array {
        [$start, $end] = $this->resolveDateRange();
        $end ??= Carbon::now('UTC');
        $start ??= ($earliest = $this->baseQuery()->earliestDateCreated())
            ? Carbon::parse($earliest, 'UTC')
            : $end->copy()->subDay();

        $unit = match (true) {
            $start->diffInDays($end) > 120 => 'month',
            $start->diffInDays($end) > 21 => 'week',
            default => 'day',
        };

        $periodStart = match ($unit) {
            'month' => $start->copy()->startOfMonth(),
            'week' => $start->copy()->startOfWeek(),
            default => $start->copy()->startOfDay(),
        };

        $bucketKey = fn(Carbon $date): string => $unit === 'month' ? $date->format('Y-m') : $date->format('Y-m-d');
        $bucketLabel = fn(Carbon $date): string => $date->locale(Craft::$app->language)->translatedFormat($unit === 'month' ? 'M Y' : 'd M');

        $labels = [];

        foreach (CarbonPeriod::create($periodStart, "1 {$unit}", $end) as $bucketStart) {
            $labels[$bucketKey($bucketStart)] = $bucketLabel($bucketStart);
        }

        $configuredStatuses = $this->getStatusesArray() ?: array_keys(Payment::statuses());
        $counts = [];
        $amounts = [];

        foreach (array_keys(Payment::statuses()) as $status) {
            if (in_array($status, $configuredStatuses, true)) {
                $counts[$status] = array_fill_keys(array_keys($labels), 0);
                $amounts[$status] = array_fill_keys(array_keys($labels), 0.0);
            }
        }

        foreach ($this->baseQuery()->statusTotalsByPeriod($unit) as $row) {
            $key = $bucketKey(Carbon::parse($row['bucket'], 'UTC'));

            if (isset($counts[$row['status']][$key])) {
                $counts[$row['status']][$key] += $row['count'];
                $amounts[$row['status']][$key] += $row['amount'];
            }
        }

        $showCounts = $this->chartDisplay !== self::CHART_DISPLAY_AMOUNTS;
        $showAmounts = $this->chartDisplay !== self::CHART_DISPLAY_COUNTS;
        $datasets = [];

        foreach ($counts as $status => $bucketCounts) {
            if (array_sum($bucketCounts) === 0) continue;

            $definition = Payment::statuses()[$status];
            $rgb = self::CHART_COLORS[$definition['color']->value] ?? self::CHART_COLORS['gray'];

            if ($showCounts) {
                $datasets[] = [
                    'type' => 'bar',
                    'label' => $definition['label'],
                    'yAxisID' => 'yCount',
                    'data' => array_values($bucketCounts),
                    'backgroundColor' => "rgb({$rgb})",
                    'borderRadius' => 3,
                    'borderSkipped' => false,
                    'maxBarThickness' => 24,
                    'minBarLength' => 4,
                ];
            }

            if ($showAmounts) {
                $datasets[] = [
                    'type' => 'line',
                    'label' => $definition['label'],
                    'yAxisID' => 'yAmount',
                    'data' => array_map(fn(float $amount) => round($amount, 2), array_values($amounts[$status])),
                    'borderColor' => "rgb({$rgb})",
                    'backgroundColor' => "rgb({$rgb})",
                    'borderDash' => [8, 4],
                    'borderWidth' => 2,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'tension' => 0.3,
                    'fill' => false,
                ];
            }
        }

        return [
            'labels' => array_values($labels),
            'datasets' => $datasets,
        ];
    }

    /**
     * @return PaymentQuery
     */
    private function baseQuery(): PaymentQuery {
        [$start, $end] = $this->resolveDateRange();

        $query = Payment::find()
            ->status($this->getStatusesArray() ?: null)
            ->orderBy(match ($this->sort) {
                self::SORT_AMOUNT_DESC => ['qr_payments_payments.amount' => SORT_DESC],
                self::SORT_AMOUNT_ASC => ['qr_payments_payments.amount' => SORT_ASC],
                default => ['dateCreated' => SORT_DESC],
            });

        if ($start !== null && $end !== null) {
            $query->dateCreated(['and', '>= ' . $start->toDateTimeString(), '< ' . $end->toDateTimeString()]);
        } elseif ($start !== null) {
            $query->dateCreated('>= ' . $start->toDateTimeString());
        }

        return $query;
    }
}
