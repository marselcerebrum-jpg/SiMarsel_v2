<?php

namespace App\Services;

use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use App\Repositories\PerformanceRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\PerformanceFilter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PerformanceTrendService
{
    use ComparesWithPreviousValue;

    private const MONTHS_PER_YEAR = 12;

    public function __construct(private PerformanceRepository $performanceRepository) {}

    /**
     * Build the time series of one metric, split into daily, weekly (Monday - Sunday), or monthly buckets.
     * When several years are selected, the chart compares them month by month with one dataset per year.
     * Buckets that start after today have no data yet, so their value is null.
     *
     * @return array<string, mixed>
     */
    public function trend(PerformanceFilter $filter, TrendMetric $metric, TrendPeriod $period): array
    {
        $buckets = array_merge(...array_map(
            fn (array $range) => $this->buckets($range[0], $range[1], $period),
            $filter->ranges,
        ));
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
            'labels' => array_map(fn (int $month) => CarbonImmutable::create(2000, $month, 1)->format('M'), range(1, 12)),
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
     * Split the range into buckets; the first and last bucket are cut to the selected range.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    private function buckets(CarbonImmutable $from, CarbonImmutable $to, TrendPeriod $period): array
    {
        $buckets = [];
        $start = $from;

        while ($start->lte($to)) {
            $end = match ($period) {
                TrendPeriod::Daily => $start,
                TrendPeriod::Weekly => $start->endOfWeek(CarbonInterface::SUNDAY)->startOfDay(),
                TrendPeriod::Monthly => $start->endOfMonth()->startOfDay(),
            };
            $end = $end->min($to);

            $buckets[] = ['start' => $start, 'end' => $end, 'label' => $this->label($start, $end, $period)];
            $start = $end->addDay();
        }

        return $buckets;
    }

    private function label(CarbonImmutable $start, CarbonImmutable $end, TrendPeriod $period): string
    {
        if ($period === TrendPeriod::Monthly) {
            return $start->format('M Y');
        }

        if ($start->isSameDay($end)) {
            return $start->format('d M Y');
        }

        $startFormat = match (true) {
            $start->isSameMonth($end) => 'd',
            $start->isSameYear($end) => 'd M',
            default => 'd M Y',
        };

        return $start->format($startFormat).' - '.$end->format('d M Y');
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
        $bucketOfDay = $this->bucketOfDay($buckets);
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
     * Map every date (Y-m-d) in the range to the index of its bucket.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $buckets
     * @return array<string, int>
     */
    private function bucketOfDay(array $buckets): array
    {
        $map = [];

        foreach ($buckets as $index => $bucket) {
            for ($day = $bucket['start']; $day->lte($bucket['end']); $day = $day->addDay()) {
                $map[$day->toDateString()] = $index;
            }
        }

        return $map;
    }
}
