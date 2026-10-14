<?php

namespace App\Services;

use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use App\Repositories\PerformanceRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class PerformanceTrendService
{
    use ComparesWithPreviousValue;

    public function __construct(private PerformanceRepository $performanceRepository) {}

    /**
     * Build the time series of one metric, split into daily, weekly (Monday - Sunday), or monthly buckets.
     *
     * @return array<string, mixed>
     */
    public function trend(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $platformSlug,
        TrendMetric $metric,
        TrendPeriod $period,
    ): array {
        $platformId = $platformSlug ? $this->performanceRepository->findPlatformIdBySlug($platformSlug) : null;

        $buckets = $this->buckets($from, $to, $period);
        [$values, $hasData] = $this->values($metric, $buckets, $from, $to, $platformId);

        return [
            'filter' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'platform' => $platformSlug,
            ],
            'metric' => [
                'key' => $metric->value,
                'label' => $metric->label(),
                'unit' => $metric->unit(),
            ],
            'period' => $period->value,
            'is_empty' => ! $hasData,
            'message' => $hasData ? null : 'Data tidak tersedia untuk filter yang dipilih.',
            'labels' => array_column($buckets, 'label'),
            'datasets' => [
                ['label' => $metric->label(), 'data' => $values],
            ],
            'rows' => $this->rows($buckets, $values),
        ];
    }

    /**
     * Table rows with the change compared to the previous row; the first row has no previous row.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $buckets
     * @param  list<int|float|null>  $values
     * @return list<array<string, mixed>>
     */
    private function rows(array $buckets, array $values): array
    {
        return array_map(fn (int $index) => [
            'label' => $buckets[$index]['label'],
            'period_start' => $buckets[$index]['start']->toDateString(),
            'period_end' => $buckets[$index]['end']->toDateString(),
            'value' => $values[$index],
            ...($index === 0
                ? ['change_percentage' => null, 'trend' => null]
                : $this->changeFromPrevious($values[$index], $values[$index - 1])),
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
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $platformId,
    ): array {
        $bucketOfDay = $this->bucketOfDay($buckets);
        $empty = array_fill(0, count($buckets), 0);

        return match ($metric) {
            TrendMetric::Revenue => $this->transactionValues('revenue', $empty, $bucketOfDay, $from, $to, $platformId),
            TrendMetric::Transactions => $this->transactionValues('transactions', $empty, $bucketOfDay, $from, $to, $platformId),
            TrendMetric::NewUsers => $this->transactionValues('new_users', $empty, $bucketOfDay, $from, $to, $platformId),
            TrendMetric::RepeatOrders => $this->transactionValues('repeat_orders', $empty, $bucketOfDay, $from, $to, $platformId),
            TrendMetric::Downloaders => $this->downloaderValues($empty, $bucketOfDay, $from, $to, $platformId),
            TrendMetric::Conversion => $this->conversionValues($empty, $bucketOfDay, $from, $to, $platformId),
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
    private function transactionValues(string $column, array $values, array $bucketOfDay, CarbonImmutable $from, CarbonImmutable $to, ?int $platformId): array
    {
        $rows = $this->performanceRepository->dailyTransactionTotals($from, $to, $platformId);

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
    private function downloaderValues(array $values, array $bucketOfDay, CarbonImmutable $from, CarbonImmutable $to, ?int $platformId): array
    {
        $rows = $this->performanceRepository->dailyDownloaderTotals($from, $to, $platformId);

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
    private function conversionValues(array $values, array $bucketOfDay, CarbonImmutable $from, CarbonImmutable $to, ?int $platformId): array
    {
        [$downloaders, $hasDownloaders] = $this->downloaderValues($values, $bucketOfDay, $from, $to, $platformId);
        [$transactions, $hasTransactions] = $this->transactionValues('transactions', $values, $bucketOfDay, $from, $to, $platformId);

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
