<?php

namespace App\Support\Period;

use Carbon\CarbonImmutable;

/**
 * The part of each period used to calculate the change: only active days that have an equivalent date,
 * against the comparison days they map to. Each side can be split into several [start, end) ranges.
 */
final class ChangeBasis
{
    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $activeRanges  [start, exclusive end]
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $comparisonRanges  [start, exclusive end]
     */
    public function __construct(
        public readonly array $activeRanges,
        public readonly array $comparisonRanges,
        public readonly bool $isTrimmed,
    ) {}

    /**
     * Inclusive calendar-day ranges, for queries that filter by date.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public static function dateRanges(array $ranges): array
    {
        return array_map(fn (array $range) => [$range[0]->startOfDay(), PeriodWindow::lastDay($range[1])], $ranges);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_trimmed' => $this->isTrimmed,
            'active_days' => self::days($this->activeRanges),
            'comparison_days' => self::days($this->comparisonRanges),
            'active' => self::bounds($this->activeRanges),
            'comparison' => self::bounds($this->comparisonRanges),
        ];
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     */
    private static function days(array $ranges): int
    {
        return array_sum(array_map(
            fn (array $range) => (int) $range[0]->startOfDay()->diffInDays($range[1]) + 1,
            self::dateRanges($ranges),
        ));
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return list<array{date_from: string, date_to: string, start_at: string, end_at: string}>
     */
    private static function bounds(array $ranges): array
    {
        return array_map(fn (array $range) => [
            'date_from' => $range[0]->toDateString(),
            'date_to' => PeriodWindow::lastDay($range[1])->toDateString(),
            'start_at' => $range[0]->toIso8601String(),
            'end_at' => $range[1]->toIso8601String(),
        ], $ranges);
    }
}
