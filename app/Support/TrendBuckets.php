<?php

namespace App\Support;

use App\Enums\TrendPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Daily, weekly (Monday - Sunday), or monthly chart buckets shared by every trend chart.
 */
final class TrendBuckets
{
    /** Indonesian short month names, matching Intl.DateTimeFormat('id-ID') on the frontend. */
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month - 1];
    }

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
                TrendPeriod::Hourly => throw new InvalidArgumentException('Daily totals cannot be split per hour.'),
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

    /**
     * Buckets covering [startAt, endAt) with exclusive ends, for a trend read from transaction times.
     * Weeks run Monday - Sunday and months are calendar months; the first and last bucket are cut to the range.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public static function windows(CarbonImmutable $startAt, CarbonImmutable $endAt, TrendPeriod $period): array
    {
        $buckets = [];

        for ($start = $startAt; $start->lt($endAt); $start = $end) {
            $end = match ($period) {
                TrendPeriod::Hourly => $start->startOfHour()->addHour(),
                TrendPeriod::Daily => $start->startOfDay()->addDay(),
                TrendPeriod::Weekly => $start->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()->addDay(),
                TrendPeriod::Monthly => $start->startOfMonth()->addMonth(),
            };
            $end = $end->min($endAt);

            $buckets[] = ['start' => $start, 'end' => $end, 'label' => self::windowLabel($start, $end, $period)];
        }

        return $buckets;
    }

    /**
     * The comparison bucket of every active bucket, matched by position, with its own original times.
     * Months pair calendar months; other granularities take the same length (hours or days) from the
     * comparison start, so a partial first week is compared with the same number of days.
     * A position the comparison range does not reach has no comparison bucket (null).
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $active
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}|null>
     */
    public static function comparisonWindows(array $active, CarbonImmutable $startAt, CarbonImmutable $endAt, TrendPeriod $period): array
    {
        if ($period === TrendPeriod::Monthly) {
            $months = self::windows($startAt, $endAt, $period);

            return array_map(fn (int $index) => $months[$index] ?? null, array_keys($active));
        }

        $buckets = [];
        $start = $startAt;

        foreach ($active as $bucket) {
            if ($start->gte($endAt)) {
                $buckets[] = null;

                continue;
            }

            $end = $period === TrendPeriod::Hourly
                ? $start->addHours((int) $bucket['start']->diffInHours($bucket['end']))
                : $start->addDays((int) $bucket['start']->diffInDays($bucket['end']));
            $end = $end->min($endAt);

            $buckets[] = ['start' => $start, 'end' => $end, 'label' => self::windowLabel($start, $end, $period)];
            $start = $end;
        }

        return $buckets;
    }

    /**
     * Map every hour (hourly) or day (other granularities) covered by the buckets to the bucket index.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}|null>  $buckets
     * @return array<string, int>
     */
    public static function indexByUnit(array $buckets, TrendPeriod $period): array
    {
        $map = [];

        foreach ($buckets as $index => $bucket) {
            if ($bucket === null) {
                continue;
            }

            for ($unit = $bucket['start']; $unit->lt($bucket['end']); $unit = $period === TrendPeriod::Hourly ? $unit->addHour() : $unit->addDay()) {
                $map[self::unitKey($unit, $period)] = $index;
            }
        }

        return $map;
    }

    public static function unitKey(CarbonImmutable $moment, TrendPeriod $period): string
    {
        return $moment->format($period === TrendPeriod::Hourly ? 'Y-m-d H' : 'Y-m-d');
    }

    /**
     * Hourly labels show the date and the starting hour (WIB), such as "20 Nov 2026 14.00";
     * other labels match the daily, weekly, and monthly trend labels.
     */
    private static function windowLabel(CarbonImmutable $start, CarbonImmutable $end, TrendPeriod $period): string
    {
        if ($period === TrendPeriod::Hourly) {
            return $start->format('d').' '.self::monthName($start->month).' '.$start->year.' '.$start->format('H.i');
        }

        $lastDay = $end->eq($end->startOfDay()) ? $end->subDay() : $end->startOfDay();

        return self::label($start, $lastDay, $period);
    }

    private static function label(CarbonImmutable $start, CarbonImmutable $end, TrendPeriod $period): string
    {
        if ($period === TrendPeriod::Monthly) {
            return self::monthName($start->month).' '.$start->year;
        }

        $full = fn (CarbonImmutable $date) => $date->format('d').' '.self::monthName($date->month).' '.$date->year;

        if ($start->isSameDay($end)) {
            return $full($start);
        }

        $startLabel = match (true) {
            $start->isSameMonth($end) => $start->format('d'),
            $start->isSameYear($end) => $start->format('d').' '.self::monthName($start->month),
            default => $full($start),
        };

        return $startLabel.' - '.$full($end);
    }
}
