<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\PeriodStateBasis;
use App\Enums\TransactionDateBasis;
use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use App\Repositories\DashboardTransactionRepository;
use App\Repositories\PerformanceRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodWindow;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\TransactionRecord;
use App\Support\TrendBuckets;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PerformanceTrendService
{
    use ComparesWithPreviousValue;

    private const MONTHS_PER_YEAR = 12;

    /** The values of each sales trend bucket side. */
    private const SALES_VALUES = ['revenue', 'success_payments', 'total_checkouts'];

    public function __construct(
        private PerformanceRepository $performanceRepository,
        private DashboardTransactionRepository $dashboardTransactions,
        private TransactionAggregationService $aggregations,
        private ComparisonSnapshotService $comparisonSnapshots,
    ) {}

    /**
     * The Omzet, Transaksi Berhasil, and Total Transaksi trend of a resolved period, split into hourly, daily,
     * weekly, or monthly buckets. Every bucket has an active side and a comparison side, each with its own
     * original times. Omzet and Transaksi Berhasil follow the last valid paid date of Success purchases (claims
     * excluded); Total Transaksi follows markaz_created_at and includes coupon claims. Group members never count.
     *
     * A bucket after the complete-data cutoff is unavailable with null values, never 0; a complete bucket without
     * transactions has 0. The active side reads the latest stored state. The comparison side reads the latest
     * state for whole days and the recorded dashboard_snapshots of the equivalent-hour day, so a later change to
     * a transaction never rewrites a recorded comparison bucket.
     *
     * @return array<string, mixed>
     */
    public function salesTrend(PerformanceFilter $filter, TrendPeriod $granularity): array
    {
        $period = $filter->period ?? throw new InvalidArgumentException('The sales trend needs a resolved period.');
        $platformIds = $this->dashboardTransactions->platformIds($period->platforms);
        $active = $period->active;
        $comparison = $period->comparison;
        $activeEnd = $active->dateTo->addDay();

        $activeBuckets = TrendBuckets::windows($active->dateFrom, $activeEnd, $granularity);
        $activeRecords = $this->records($platformIds, $active->dateFrom, $activeEnd);
        $activeSides = $this->latestSides($activeRecords, $activeBuckets, $platformIds, $active->cutoffAt, $granularity);

        $comparisonSides = $comparison === null
            ? array_fill(0, count($activeBuckets), null)
            : $this->comparisonSides(
                $comparison,
                TrendBuckets::comparisonWindows($activeBuckets, $comparison->dateFrom, $comparison->dateTo->addDay(), $granularity),
                $platformIds,
                $granularity,
            );

        return [
            'filter' => $filter->toArray(),
            'granularity' => $granularity->value,
            'default_granularity' => TrendPeriod::defaultFor($period)->value,
            'granularities' => array_values(array_map(
                fn (TrendPeriod $option) => $option->value,
                array_filter(TrendPeriod::cases(), fn (TrendPeriod $option) => $option->maxDays() === null || $active->days() <= $option->maxDays()),
            )),
            'series' => self::salesSeries(),
            'is_empty' => $activeRecords === [],
            'message' => $activeRecords === [] ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => $this->latestValues($activeRecords, $platformIds, $active->dateFrom, $activeEnd, $active->cutoffAt),
            'buckets' => array_map(fn (int $index) => [
                'index' => $index,
                'active' => $activeSides[$index],
                'comparison' => $comparisonSides[$index],
            ], array_keys($activeBuckets)),
        ];
    }

    /**
     * The chart series: Omzet and Omzet Pembanding on the Rupiah axis, Transaksi Berhasil and Total Transaksi
     * on the transaction count axis. Total Transaksi starts hidden and is turned on from the legend.
     *
     * @return list<array<string, mixed>>
     */
    public static function salesSeries(): array
    {
        return [
            ['key' => 'revenue', 'label' => 'Omzet', 'side' => 'active', 'value' => 'revenue', 'unit' => 'currency', 'axis' => 'currency', 'date_basis' => 'paid', 'visible' => true],
            ['key' => 'comparison_revenue', 'label' => 'Omzet Pembanding', 'side' => 'comparison', 'value' => 'revenue', 'unit' => 'currency', 'axis' => 'currency', 'date_basis' => 'paid', 'visible' => true],
            ['key' => 'success_payments', 'label' => 'Transaksi Berhasil', 'side' => 'active', 'value' => 'success_payments', 'unit' => 'count', 'axis' => 'count', 'date_basis' => 'paid', 'visible' => true],
            ['key' => 'total_checkouts', 'label' => 'Total Transaksi', 'side' => 'active', 'value' => 'total_checkouts', 'unit' => 'count', 'axis' => 'count', 'date_basis' => 'created', 'visible' => false],
        ];
    }

    /**
     * Build the time series of one metric, split into daily, weekly (Monday - Sunday), or monthly buckets.
     * When several years are selected, the chart compares them month by month with one dataset per year.
     * Buckets that start after today have no data yet, so their value is null.
     *
     * @return array<string, mixed>
     */
    public function trend(PerformanceFilter $filter, TrendMetric $metric, TrendPeriod $period): array
    {
        $buckets = TrendBuckets::forFilter($filter, $period);
        [$values, $hasData] = $this->values($metric, $buckets, $filter);

        $today = CarbonImmutable::today();
        $values = array_map(
            fn (array $bucket, int|float|null $value) => $bucket['start']->gt($today) ? null : $value,
            $buckets,
            $values,
        );

        return [
            'filter' => $filter->toArray(),
            'metric' => [
                'key' => $metric->value,
                'label' => $metric->label(),
                'unit' => $metric->unit(),
            ],
            'period' => $period->value,
            'is_empty' => ! $hasData,
            'message' => $hasData ? null : 'Data tidak tersedia untuk filter yang dipilih.',
            ...($filter->comparesYears()
                ? $this->yearComparisonChart($buckets, $values)
                : [
                    'labels' => array_column($buckets, 'label'),
                    'datasets' => [
                        ['label' => $metric->label(), 'data' => $values],
                    ],
                ]),
            'rows' => $this->rows($buckets, $values, $filter->comparesYears() ? self::MONTHS_PER_YEAR : 1),
        ];
    }

    /**
     * Month labels (Jan - Dec) with one dataset per selected year.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $buckets
     * @param  list<int|float|null>  $values
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float|null>}>}
     */
    private function yearComparisonChart(array $buckets, array $values): array
    {
        $datasets = [];

        foreach ($buckets as $index => $bucket) {
            $datasets[$bucket['start']->year][] = $values[$index];
        }

        return [
            'labels' => array_map(TrendBuckets::monthName(...), range(1, 12)),
            'datasets' => array_map(
                fn (int $year, array $data) => ['label' => (string) $year, 'data' => $data],
                array_keys($datasets),
                $datasets,
            ),
        ];
    }

    /**
     * Table rows with the change compared to an earlier row: the previous row, or, when several years
     * are compared, the same month of the previous selected year ($step = 12).
     * Rows without an earlier row, and rows after today, have no change.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $buckets
     * @param  list<int|float|null>  $values
     * @return list<array<string, mixed>>
     */
    private function rows(array $buckets, array $values, int $step): array
    {
        $today = CarbonImmutable::today();

        return array_map(fn (int $index) => [
            'label' => $buckets[$index]['label'],
            'period_start' => $buckets[$index]['start']->toDateString(),
            'period_end' => $buckets[$index]['end']->toDateString(),
            'value' => $values[$index],
            ...($index < $step || $buckets[$index]['start']->gt($today)
                ? ['change_percentage' => null, 'trend' => null]
                : $this->changeFromPrevious($values[$index], $values[$index - $step])),
        ], array_keys($buckets));
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $buckets
     * @return array{0: list<int|float|null>, 1: bool} The values per bucket and whether any source data exists.
     */
    private function values(
        TrendMetric $metric,
        array $buckets,
        PerformanceFilter $filter,
    ): array {
        $bucketOfDay = TrendBuckets::bucketOfDay($buckets);
        $empty = array_fill(0, count($buckets), 0);

        return match ($metric) {
            TrendMetric::Revenue => $this->transactionValues('revenue', $empty, $bucketOfDay, $filter),
            TrendMetric::Transactions => $this->transactionValues('transactions', $empty, $bucketOfDay, $filter),
            TrendMetric::NewUsers => $this->transactionValues('new_users', $empty, $bucketOfDay, $filter),
            TrendMetric::RepeatOrders => $this->transactionValues('repeat_orders', $empty, $bucketOfDay, $filter),
            TrendMetric::Downloaders => $this->downloaderValues($empty, $bucketOfDay, $filter),
            TrendMetric::Conversion => $this->conversionValues($empty, $bucketOfDay, $filter),
        };
    }

    /**
     * Sum one daily transaction column into the buckets.
     *
     * @param  'revenue'|'transactions'|'new_users'|'repeat_orders'  $column
     * @param  list<int>  $values
     * @param  array<string, int>  $bucketOfDay
     * @return array{0: list<int|float>, 1: bool}
     */
    private function transactionValues(string $column, array $values, array $bucketOfDay, PerformanceFilter $filter): array
    {
        $rows = $this->performanceRepository->dailyTransactionTotals($filter);

        foreach ($rows as $row) {
            $values[$bucketOfDay[$row->day]] += $column === 'revenue' ? (float) $row->revenue : (int) $row->{$column};
        }

        if ($column === 'revenue') {
            $values = array_map(fn (int|float $value) => round($value, 2), $values);
        }

        return [$values, $rows->isNotEmpty()];
    }

    /**
     * @param  list<int>  $values
     * @param  array<string, int>  $bucketOfDay
     * @return array{0: list<int>, 1: bool}
     */
    private function downloaderValues(array $values, array $bucketOfDay, PerformanceFilter $filter): array
    {
        $rows = $this->performanceRepository->dailyDownloaderTotals($filter);

        foreach ($rows as $row) {
            $values[$bucketOfDay[$row->day]] += (int) $row->total;
        }

        return [$values, $rows->isNotEmpty()];
    }

    /**
     * Successful transactions divided by downloaders in each bucket; null when the bucket has no downloaders.
     *
     * @param  list<int>  $values
     * @param  array<string, int>  $bucketOfDay
     * @return array{0: list<float|null>, 1: bool}
     */
    private function conversionValues(array $values, array $bucketOfDay, PerformanceFilter $filter): array
    {
        [$downloaders, $hasDownloaders] = $this->downloaderValues($values, $bucketOfDay, $filter);
        [$transactions, $hasTransactions] = $this->transactionValues('transactions', $values, $bucketOfDay, $filter);

        $conversion = array_map(
            fn (int $downloaderTotal, int $transactionTotal) => $downloaderTotal > 0
                ? round($transactionTotal / $downloaderTotal * 100, 2)
                : null,
            $downloaders,
            $transactions,
        );

        return [$conversion, $hasDownloaders || $hasTransactions];
    }

    /**
     * @param  list<int>  $platformIds
     * @return list<TransactionRecord>
     */
    private function records(array $platformIds, CarbonImmutable $startAt, CarbonImmutable $endAt): array
    {
        if ($platformIds === []) {
            return [];
        }

        return array_map(
            TransactionRecord::fromArray(...),
            $this->dashboardTransactions->contractRows($platformIds, $startAt, $endAt),
        );
    }

    /**
     * Bucket sides read from the latest stored state, up to the complete-data cutoff.
     *
     * @param  list<TransactionRecord>  $records
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}|null>  $buckets
     * @param  list<int>  $platformIds
     * @return list<array<string, mixed>|null>
     */
    private function latestSides(array $records, array $buckets, array $platformIds, ?CarbonImmutable $completeUntil, TrendPeriod $granularity): array
    {
        $partitions = $this->partition($records, $buckets, $granularity);

        return array_map(fn (int $index) => $buckets[$index] === null ? null : $this->side(
            $buckets[$index],
            $this->latestValues($partitions[$index] ?? [], $platformIds, $buckets[$index]['start'], $buckets[$index]['end'], $completeUntil),
            'latest',
        ), array_keys($buckets));
    }

    /**
     * A comparison read from the latest state uses it for every bucket. An equivalent-hour comparison
     * (state basis at_cutoff) uses the latest state for whole days before the cutoff day and the recorded
     * snapshots of the cutoff day for its hours before the cutoff. A bucket that needs a missing or incomplete
     * snapshot stays empty (null values), and buckets from the cutoff on are unavailable.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}|null>  $buckets
     * @param  list<int>  $platformIds
     * @return list<array<string, mixed>|null>
     */
    private function comparisonSides(PeriodWindow $window, array $buckets, array $platformIds, TrendPeriod $granularity): array
    {
        $records = $this->records($platformIds, $window->dateFrom, $window->dateTo->addDay());

        if ($window->stateBasis !== PeriodStateBasis::AtCutoff || $window->availability === DataAvailability::Unavailable) {
            return $this->latestSides($records, $buckets, $platformIds, $window->cutoffAt, $granularity);
        }

        $cutoff = $window->cutoffAt;
        $cutoffDay = $cutoff->startOfDay();
        $snapshots = $this->dashboardTransactions->daySnapshots($platformIds, $cutoffDay);
        $partitions = $this->partition($records, $buckets, $granularity);

        return array_map(function (int $index) use ($buckets, $partitions, $platformIds, $cutoff, $cutoffDay, $snapshots) {
            $bucket = $buckets[$index];

            if ($bucket === null) {
                return null;
            }

            if ($bucket['start']->gte($cutoff)) {
                return $this->side($bucket, $this->unavailableValues(), null);
            }

            $end = $bucket['end']->min($cutoff);
            $values = array_fill_keys(self::SALES_VALUES, 0);
            $source = 'latest';

            if ($bucket['start']->lt($cutoffDay)) {
                $values = $this->addValues($values, $this->latestValues(
                    $partitions[$index] ?? [],
                    $platformIds,
                    $bucket['start'],
                    $end->min($cutoffDay),
                    $cutoffDay,
                )['values']);
            }

            if ($end->gt($cutoffDay)) {
                $source = 'snapshot';
                $from = $bucket['start']->max($cutoffDay);
                $until = $this->recordedValues($snapshots, $platformIds, $cutoffDay, $end);
                $since = $from->eq($cutoffDay)
                    ? array_fill_keys(self::SALES_VALUES, 0)
                    : $this->recordedValues($snapshots, $platformIds, $cutoffDay, $from);

                if ($until === null || $since === null) {
                    return $this->side($bucket, $this->unavailableValues(), $source);
                }

                foreach (self::SALES_VALUES as $key) {
                    $values[$key] += $until[$key] - $since[$key];
                }
            }

            return $this->side($bucket, [
                'availability' => ($end->lt($bucket['end']) ? DataAvailability::Partial : DataAvailability::Complete)->value,
                'as_of' => $end->toIso8601String(),
                'values' => $this->roundValues($values),
            ], $source);
        }, array_keys($buckets));
    }

    /**
     * The shared aggregation of [startAt, endAt) on both date bases, limited by the complete-data cutoff.
     *
     * @param  list<TransactionRecord>  $records
     * @param  list<int>  $platformIds
     * @return array{availability: string, as_of: string|null, values: array<string, int|float>|null}
     */
    private function latestValues(array $records, array $platformIds, CarbonImmutable $startAt, CarbonImmutable $endAt, ?CarbonImmutable $completeUntil): array
    {
        if ($platformIds === []) {
            return $this->unavailableValues();
        }

        $aggregate = fn (TransactionDateBasis $basis) => $this->aggregations->aggregate(
            $records,
            AggregationFilter::forWindow($platformIds, $startAt, $endAt, $basis, $completeUntil),
        );
        $paid = $aggregate(TransactionDateBasis::Paid);

        if ($paid['availability'] === DataAvailability::Unavailable->value) {
            return $this->unavailableValues();
        }

        return [
            'availability' => $paid['availability'],
            'as_of' => $paid['as_of'],
            'values' => $this->roundValues([
                'revenue' => $paid['metrics']['revenue'],
                'success_payments' => $paid['metrics']['success_payments'],
                'total_checkouts' => $aggregate(TransactionDateBasis::Created)['metrics']['total_checkouts'],
            ]),
        ];
    }

    /**
     * The cumulative values of one day recorded at $cutoffAt, combined over the selected platforms; null when
     * a platform has no complete snapshot at exactly that cutoff or the snapshot lacks one of the values.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @param  list<int>  $platformIds
     * @return array<string, int|float>|null
     */
    private function recordedValues(array $snapshots, array $platformIds, CarbonImmutable $day, CarbonImmutable $cutoffAt): ?array
    {
        $recorded = $this->comparisonSnapshots->read($snapshots, $platformIds, ComparisonSnapshotService::DATASET_TRANSACTIONS, $day, $day, $cutoffAt);
        $values = [];

        foreach (self::SALES_VALUES as $key) {
            if (! is_int($recorded['values'][$key] ?? null) && ! is_float($recorded['values'][$key] ?? null)) {
                return null;
            }

            $values[$key] = $recorded['values'][$key];
        }

        return $values;
    }

    /**
     * Groups the records by the bucket of their created time and the bucket of their payment time.
     *
     * @param  list<TransactionRecord>  $records
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}|null>  $buckets
     * @return array<int, list<TransactionRecord>>
     */
    private function partition(array $records, array $buckets, TrendPeriod $granularity): array
    {
        $indexByUnit = TrendBuckets::indexByUnit($buckets, $granularity);
        $partitions = [];

        foreach ($records as $record) {
            $paidAt = $record->paymentAt();
            $indexes = array_unique(array_filter([
                $indexByUnit[TrendBuckets::unitKey($record->markazCreatedAt, $granularity)] ?? null,
                $paidAt === null ? null : ($indexByUnit[TrendBuckets::unitKey($paidAt, $granularity)] ?? null),
            ], fn (?int $index) => $index !== null));

            foreach ($indexes as $index) {
                $partitions[$index][] = $record;
            }
        }

        return $partitions;
    }

    /**
     * One side of a bucket with its original times.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, label: string}  $bucket
     * @param  array{availability: string, as_of: string|null, values: array<string, int|float>|null}  $values
     * @return array<string, mixed>
     */
    private function side(array $bucket, array $values, ?string $source): array
    {
        return [
            'start_at' => $bucket['start']->toIso8601String(),
            'end_at' => $bucket['end']->toIso8601String(),
            'label' => $bucket['label'],
            'availability' => $values['availability'],
            'as_of' => $values['as_of'],
            'source' => $source,
            'values' => $values['values'],
        ];
    }

    /**
     * @return array{availability: string, as_of: null, values: null}
     */
    private function unavailableValues(): array
    {
        return ['availability' => DataAvailability::Unavailable->value, 'as_of' => null, 'values' => null];
    }

    /**
     * @param  array<string, int|float>  $left
     * @param  array<string, int|float>  $right
     * @return array<string, int|float>
     */
    private function addValues(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] += $value;
        }

        return $left;
    }

    /**
     * @param  array<string, int|float>  $values
     * @return array{revenue: float, success_payments: int, total_checkouts: int}
     */
    private function roundValues(array $values): array
    {
        return [
            'revenue' => round((float) $values['revenue'], 2),
            'success_payments' => (int) $values['success_payments'],
            'total_checkouts' => (int) $values['total_checkouts'],
        ];
    }
}
