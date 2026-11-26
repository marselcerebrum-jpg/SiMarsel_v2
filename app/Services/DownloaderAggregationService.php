<?php

namespace App\Services;

use App\Enums\DashboardDataset;
use App\Enums\DataAvailability;
use App\Enums\PeriodStateBasis;
use App\Enums\TrendPeriod;
use App\Repositories\DownloaderRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodWindow;
use App\Support\Period\ResolvedPeriod;
use App\Support\TransactionAggregation\TransactionRecord;
use App\Support\TrendBuckets;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The shared Downloader aggregation of the chart, the Lihat Rincian table, the period totals, the platform
 * shares, and the export. A whole day reads the latest daily total in downloaders.total, so a total lowered
 * after accounts were deleted replaces the earlier one. Part of a day reads a cumulative value: the latest daily
 * total at the active data limit, otherwise the downloaders dashboard_snapshots payload recorded at exactly that
 * cutoff. Recorded snapshots are never summed with each other and are never estimated from daily totals.
 *
 * A missing value is null with availability = unavailable, never 0: a platform without a daily total for a date
 * leaves that date unknown, while a stored total of 0 stays 0.
 */
class DownloaderAggregationService
{
    use ComparesWithPreviousValue;

    public function __construct(
        private DownloaderRepository $downloaders,
        private ComparisonSnapshotService $comparisonSnapshots,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public static function series(): array
    {
        return [
            ['key' => 'downloaders', 'label' => 'Downloader', 'side' => 'active', 'unit' => 'count'],
            ['key' => 'comparison_downloaders', 'label' => 'Downloader Pembanding', 'side' => 'comparison', 'unit' => 'count'],
        ];
    }

    /**
     * Matched active and comparison points per hourly, daily, weekly, or monthly bucket, with the period totals and
     * the share of every platform. An hourly point is the day's cumulative total at the end of its hour (as_of).
     * Other buckets add the daily totals of their days.
     *
     * @return array<string, mixed>
     */
    public function trend(PerformanceFilter $filter, TrendPeriod $granularity): array
    {
        [$period, $platforms, $active, $comparison] = $this->sources($filter);

        $activeBuckets = TrendBuckets::windows($period->active->dateFrom, $period->active->dateTo->addDay(), $granularity);
        $comparisonBuckets = $period->comparison === null
            ? array_fill(0, count($activeBuckets), null)
            : TrendBuckets::comparisonWindows($activeBuckets, $period->comparison->dateFrom, $period->comparison->dateTo->addDay(), $granularity);

        $isEmpty = $active['daily'] === [] && ! collect($active['snapshots'])->flatten(1)->contains(
            fn (array $snapshot) => ($snapshot['payload']['daily'] ?? []) !== [],
        );
        $totals = $this->totals($active, $comparison, $period->changeBasis?->activeRanges, $period->changeBasis?->comparisonRanges);
        unset($totals['change_basis']);

        return [
            'filter' => $filter->toArray(),
            'granularity' => $granularity->value,
            'default_granularity' => TrendPeriod::defaultFor($period)->value,
            'granularities' => array_values(array_map(
                fn (TrendPeriod $option) => $option->value,
                array_filter(TrendPeriod::cases(), fn (TrendPeriod $option) => $option->maxDays() === null || $period->active->days() <= $option->maxDays()),
            )),
            'series' => self::series(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => $totals,
            'platforms' => $this->platformShares($active, $platforms, $totals['active']['value']),
            'buckets' => array_map(function (int $index) use ($activeBuckets, $comparisonBuckets, $active, $comparison, $granularity) {
                $activeSide = $this->side($activeBuckets[$index], $this->value($active, $active['platform_ids'], $activeBuckets[$index], $granularity));
                $comparisonSide = $comparison === null || $comparisonBuckets[$index] === null
                    ? null
                    : $this->side($comparisonBuckets[$index], $this->value($comparison, $comparison['platform_ids'], $comparisonBuckets[$index], $granularity));

                return [
                    'index' => $index,
                    'active' => $activeSide,
                    'comparison' => $comparisonSide,
                    'change' => $this->change($activeSide, $comparisonSide),
                ];
            }, array_keys($activeBuckets)),
        ];
    }

    /**
     * The whole-period Downloader totals read by the Ringkasan KPI: the active and comparison totals, their change,
     * and the totals of the change basis ranges (null when unknown).
     *
     * @return array{active: array<string, mixed>, comparison: array<string, mixed>|null, change: array<string, mixed>, change_basis: array{active: int|null, comparison: int|null}}
     */
    public function periodTotals(PerformanceFilter $filter): array
    {
        [$period, , $active, $comparison] = $this->sources($filter);

        return $this->totals($active, $comparison, $period->changeBasis?->activeRanges, $period->changeBasis?->comparisonRanges);
    }

    /**
     * The whole-period Downloader total of each selected platform, read with the same active period (and cutoff)
     * as the transaction dataset, so a platform ratio never mixes ranges. A platform without a daily total stays
     * null (availability unavailable); another platform or period never fills the gap.
     *
     * @return array<string, array{value: int|null, availability: string}> platform slug => total
     */
    public function platformPeriodTotals(PerformanceFilter $filter): array
    {
        [, $platforms, $active] = $this->sources($filter);
        $bucket = $this->periodBucket($active['window']);
        $totals = [];

        foreach ($platforms as $platformId => $platform) {
            $value = $this->value($active, [$platformId], $bucket, TrendPeriod::Daily);
            $totals[$platform['slug']] = ['value' => $value['value'], 'availability' => $value['availability']];
        }

        return $totals;
    }

    /**
     * @return array{0: ResolvedPeriod, 1: array<int, array{slug: string, name: string}>, 2: array<string, mixed>, 3: array<string, mixed>|null}
     */
    private function sources(PerformanceFilter $filter): array
    {
        $period = $filter->period ?? throw new InvalidArgumentException('The Downloader aggregation needs a resolved period.');
        $platforms = $this->downloaders->platforms($period->platforms);
        $platformIds = array_keys($platforms);

        return [
            $period,
            $platforms,
            $this->source($period->active, $platformIds),
            $period->comparison === null ? null : $this->source($period->comparison, $platformIds),
        ];
    }

    /**
     * The stored data one period reads.
     *
     * @param  list<int>  $platformIds
     * @return array{window: PeriodWindow, platform_ids: list<int>, daily: array<int, array<string, int>>, snapshots: array<string, list<array<string, mixed>>>}
     */
    private function source(PeriodWindow $window, array $platformIds): array
    {
        $snapshots = [];

        if ($platformIds !== []) {
            foreach ($this->downloaders->snapshots($platformIds, $window->dateFrom, $window->dateTo->addDay()) as $snapshot) {
                $snapshots[$this->snapshotKey($snapshot['date_from'], $snapshot['date_to'], $snapshot['cutoff_at'])][] = $snapshot;
            }
        }

        return [
            'window' => $window,
            'platform_ids' => $platformIds,
            'daily' => $platformIds === [] ? [] : $this->downloaders->dailyTotals($platformIds, $window->dateFrom, $window->dateTo),
            'snapshots' => $snapshots,
        ];
    }

    /**
     * The whole-period total of both sides and its change over the change basis ranges.
     *
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>|null  $comparison
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>|null  $activeRanges
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>|null  $comparisonRanges
     * @return array<string, mixed>
     */
    private function totals(array $active, ?array $comparison, ?array $activeRanges, ?array $comparisonRanges): array
    {
        $whole = fn (array $source) => $this->value($source, $source['platform_ids'], $this->periodBucket($source['window']), TrendPeriod::Daily);
        $activeTotal = $whole($active);
        $comparisonTotal = $comparison === null ? null : $whole($comparison);

        $activeBasis = $activeRanges === null ? null : $this->rangesTotal($active, $activeRanges);
        $comparisonBasis = $comparison === null || $comparisonRanges === null ? null : $this->rangesTotal($comparison, $comparisonRanges);

        return [
            'active' => $activeTotal,
            'comparison' => $comparisonTotal,
            'change' => $activeBasis === null || $comparisonBasis === null
                ? $this->noChange()
                : [...$this->changeFromPrevious($activeBasis, $comparisonBasis), 'is_comparable' => true],
            'change_basis' => ['active' => $activeBasis, 'comparison' => $comparisonBasis],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     */
    private function rangesTotal(array $source, array $ranges): ?int
    {
        $total = 0;

        foreach ($ranges as [$startAt, $endAt]) {
            $value = $this->value($source, $source['platform_ids'], ['start' => $startAt, 'end' => $endAt, 'label' => ''], TrendPeriod::Daily);

            if ($value['value'] === null) {
                return null;
            }

            $total += $value['value'];
        }

        return $total;
    }

    /**
     * Every platform's active total and its share of the combined total; the share is null when the total is 0 or unknown.
     *
     * @param  array<string, mixed>  $active
     * @param  array<int, array{slug: string, name: string}>  $platforms
     * @return list<array<string, mixed>>
     */
    private function platformShares(array $active, array $platforms, ?int $total): array
    {
        $bucket = $this->periodBucket($active['window']);

        return array_map(function (int $platformId) use ($active, $platforms, $total, $bucket) {
            $value = $this->value($active, [$platformId], $bucket, TrendPeriod::Daily);

            return [
                'slug' => $platforms[$platformId]['slug'],
                'name' => $platforms[$platformId]['name'],
                'availability' => $value['availability'],
                'as_of' => $value['as_of'],
                'value' => $value['value'],
                'share_percentage' => $value['value'] === null || ! $total ? null : round($value['value'] / $total * 100, 1),
            ];
        }, array_keys($platforms));
    }

    /**
     * The value of one bucket on one side, limited by the side's complete-data cutoff. An hourly bucket is a single
     * cumulative point at its end; a longer bucket adds the values of its days.
     *
     * @param  array<string, mixed>  $source
     * @param  list<int>  $platformIds
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, label: string}  $bucket
     * @return array{availability: string, as_of: string|null, source: string|null, value: int|null}
     */
    private function value(array $source, array $platformIds, array $bucket, TrendPeriod $granularity): array
    {
        /** @var PeriodWindow $window */
        $window = $source['window'];
        $limit = $window->cutoffAt;

        if ($platformIds === [] || $window->availability === DataAvailability::Unavailable || $limit === null || $bucket['start']->gte($limit)) {
            return $this->unavailable(null);
        }

        $until = $bucket['end']->min($limit);
        $value = 0;
        $from = 'latest';

        if ($granularity === TrendPeriod::Hourly) {
            [$value, $from] = $this->cumulative($source, $platformIds, $bucket['start']->startOfDay(), $until);
        } else {
            for ($day = $bucket['start']->startOfDay(); $day->lt($until); $day = $day->addDay()) {
                [$dayValue, $daySource] = $this->cumulative($source, $platformIds, $day, $until->min($day->addDay()));

                if ($dayValue === null) {
                    return $this->unavailable($daySource);
                }

                $value += $dayValue;
                $from = $daySource === 'snapshot' ? $daySource : $from;
            }
        }

        if ($value === null) {
            return $this->unavailable($from);
        }

        return [
            'availability' => ($until->lt($bucket['end']) ? DataAvailability::Partial : DataAvailability::Complete)->value,
            'as_of' => $until->toIso8601String(),
            'source' => $from,
            'value' => $value,
        ];
    }

    /**
     * The total of one day up to $at. The end of the day, and the active data limit (latest state), read the latest
     * daily total; an earlier hour reads the snapshot recorded at exactly that cutoff. Null when any platform has no
     * daily total or no complete snapshot for it.
     *
     * @param  array<string, mixed>  $source
     * @param  list<int>  $platformIds
     * @return array{0: int|null, 1: string}
     */
    private function cumulative(array $source, array $platformIds, CarbonImmutable $day, CarbonImmutable $at): array
    {
        /** @var PeriodWindow $window */
        $window = $source['window'];
        $date = $day->toDateString();

        if ($at->eq($day->addDay()) || ($window->stateBasis === PeriodStateBasis::Latest && $at->eq($window->cutoffAt))) {
            $totals = array_map(fn (int $id) => $source['daily'][$id][$date] ?? null, $platformIds);

            return [in_array(null, $totals, true) ? null : array_sum($totals), 'latest'];
        }

        return [$this->recorded($source, $platformIds, $day, $at), 'snapshot'];
    }

    /**
     * The cumulative total of $day recorded at $at, combined over the platforms: from the snapshot of that day, or
     * from the daily entry of the snapshot of the whole period. Null when any platform has no complete snapshot.
     *
     * @param  array<string, mixed>  $source
     * @param  list<int>  $platformIds
     */
    private function recorded(array $source, array $platformIds, CarbonImmutable $day, CarbonImmutable $at): ?int
    {
        /** @var PeriodWindow $window */
        $window = $source['window'];
        $date = $day->toDateString();

        foreach ([[$day, $day], [$window->dateFrom, $window->dateTo]] as [$from, $to]) {
            $rows = $source['snapshots'][$this->snapshotKey($from, $to, $at)] ?? [];

            if ($rows === []) {
                continue;
            }

            $values = $this->comparisonSnapshots->read($rows, $platformIds, DashboardDataset::Downloaders->value, $from, $to, $at)['values'];
            $value = $values['daily'][$date] ?? ($from->eq($to) ? ($values['total'] ?? null) : null);

            if (is_int($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Active and comparison points are compared only when both are known and cover the same part of their period.
     *
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>|null  $comparison
     * @return array{change_percentage: float|null, trend: string|null, is_comparable: bool}
     */
    private function change(array $active, ?array $comparison): array
    {
        if ($comparison === null || $active['value'] === null || $comparison['value'] === null || $active['availability'] !== $comparison['availability']) {
            return $this->noChange();
        }

        if ($active['availability'] === DataAvailability::Partial->value
            && $this->wib($active['as_of'])->format('H:i:s') !== $this->wib($comparison['as_of'])->format('H:i:s')) {
            return $this->noChange();
        }

        return [...$this->changeFromPrevious($active['value'], $comparison['value']), 'is_comparable' => true];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, label: string}  $bucket
     * @param  array{availability: string, as_of: string|null, source: string|null, value: int|null}  $value
     * @return array<string, mixed>
     */
    private function side(array $bucket, array $value): array
    {
        return [
            'start_at' => $bucket['start']->toIso8601String(),
            'end_at' => $bucket['end']->toIso8601String(),
            'label' => $bucket['label'],
            ...$value,
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, label: string}
     */
    private function periodBucket(PeriodWindow $window): array
    {
        return ['start' => $window->dateFrom, 'end' => $window->dateTo->addDay(), 'label' => ''];
    }

    /**
     * @return array{availability: string, as_of: null, source: string|null, value: null}
     */
    private function unavailable(?string $source): array
    {
        return ['availability' => DataAvailability::Unavailable->value, 'as_of' => null, 'source' => $source, 'value' => null];
    }

    /**
     * @return array{change_percentage: null, trend: null, is_comparable: false}
     */
    private function noChange(): array
    {
        return ['change_percentage' => null, 'trend' => null, 'is_comparable' => false];
    }

    private function snapshotKey(CarbonImmutable|string $dateFrom, CarbonImmutable|string $dateTo, CarbonImmutable|string $cutoffAt): string
    {
        return $this->wib($dateFrom)->toDateString().'|'.$this->wib($dateTo)->toDateString().'|'.$this->wib($cutoffAt)->format('Y-m-d H:i:s');
    }

    private function wib(CarbonImmutable|string $value): CarbonImmutable
    {
        $value = is_string($value) ? CarbonImmutable::parse($value, TransactionRecord::TIMEZONE) : $value;

        return $value->setTimezone(TransactionRecord::TIMEZONE);
    }
}
