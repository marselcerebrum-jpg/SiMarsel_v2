<?php

namespace App\Support;

use App\Enums\TrendPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Daily, weekly (Monday - Sunday), or monthly chart buckets shared by every trend chart.
 */
final class TrendBuckets
{
    /**
     * The buckets of every range of the filter, in order.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public static function forFilter(PerformanceFilter $filter, TrendPeriod $period): array
    {
        return array_merge(...array_map(
            fn (array $range) => self::split($range[0], $range[1], $period),
            $filter->ranges,
        ));
    }

    /**
     * Split the range into buckets; the first and last bucket are cut to the selected range.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public static function split(CarbonImmutable $from, CarbonImmutable $to, TrendPeriod $period): array
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

            $buckets[] = ['start' => $start, 'end' => $end, 'label' => self::label($start, $end, $period)];
            $start = $end->addDay();
        }

        return $buckets;
    }

    /**
     * Map every date (Y-m-d) in the buckets to the index of its bucket.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $buckets
     * @return array<string, int>
     */
    public static function bucketOfDay(array $buckets): array
    {
        $map = [];

        foreach ($buckets as $index => $bucket) {
            for ($day = $bucket['start']; $day->lte($bucket['end']); $day = $day->addDay()) {
                $map[$day->toDateString()] = $index;
            }
        }

        return $map;
    }

    private static function label(CarbonImmutable $start, CarbonImmutable $end, TrendPeriod $period): string
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
}
