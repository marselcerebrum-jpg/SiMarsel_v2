<?php

namespace App\Support;

use App\Enums\DataAvailability;
use App\Enums\PeriodPreset;
use App\Enums\PeriodStateBasis;
use App\Enums\TrendPeriod;
use App\Support\Period\ChangeBasis;
use App\Support\Period\ResolvedPeriod;
use Carbon\CarbonImmutable;

/**
 * The main Ringkasan Performa filter shared by every performance widget:
 * the selected platforms plus one or more date ranges and the ranges they are compared with.
 */
final class PerformanceFilter
{
    public const MODE_CURRENT_MONTH = 'current_month';

    public const MODE_DATE_RANGE = 'date_range';

    public const MODE_MONTH = 'month';

    public const MODE_YEARS = 'years';

    public const MODE_YEAR_RANGE = 'year_range';

    /**
     * @param  list<string>  $platforms  Platform slugs; empty means every platform.
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges  Sorted, non-overlapping date ranges (inclusive).
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $previousRanges  The ranges this filter is compared with.
     * @param  list<int>  $years
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $platforms,
        public readonly array $ranges,
        public readonly array $previousRanges,
        public readonly TrendPeriod $defaultPeriod,
        public readonly array $years = [],
        public readonly ?int $month = null,
        public readonly ?ResolvedPeriod $period = null,
    ) {}

    /**
     * The dates chosen by the shared period resolver; a period without equivalent dates has no comparison range.
     */
    public static function fromResolvedPeriod(ResolvedPeriod $period): self
    {
        return new self(
            $period->preset->value,
            $period->platforms,
            [[$period->active->dateFrom, $period->active->dateTo]],
            $period->comparison === null ? [] : [[$period->comparison->dateFrom, $period->comparison->dateTo]],
            $period->preset === PeriodPreset::LastYear ? TrendPeriod::Monthly : TrendPeriod::Daily,
            period: $period,
        );
    }

    /**
     * Whether previous values and the change may be shown. A resolved comparison must be available and read
     * from the latest state; an equivalent-hour comparison needs its recorded snapshot, which these queries
     * do not read, so the latest data is never shown in its place.
     */
    public function canCompare(): bool
    {
        if ($this->period === null) {
            return $this->hasComparison();
        }

        return $this->period->comparison?->availability !== null
            && $this->period->comparison->availability !== DataAvailability::Unavailable
            && $this->period->comparison->stateBasis === PeriodStateBasis::Latest
            && $this->period->changeBasis !== null;
    }

    /**
     * The active and comparison days used for the change when some days have no equivalent date.
     *
     * @return array{0: self, 1: self}|null
     */
    public function changeBasisFilters(): ?array
    {
        $basis = $this->period?->changeBasis;

        if ($basis === null || ! $basis->isTrimmed) {
            return null;
        }

        $filter = fn (array $ranges) => new self($this->mode, $this->platforms, ChangeBasis::dateRanges($ranges), [], $this->defaultPeriod);

        return [$filter($basis->activeRanges), $filter($basis->comparisonRanges)];
    }

    /**
     * From the first day of this month until today; this is the dashboard default when no period is selected.
     * Like a selected month, it is compared with the whole previous month even though this month is not over yet.
     *
     * @param  list<string>  $platforms
     */
    public static function currentMonth(array $platforms = [], ?CarbonImmutable $today = null): self
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $start = $today->startOfMonth();
        $previous = $start->subMonth();

        return new self(
            self::MODE_CURRENT_MONTH,
            $platforms,
            [[$start, $today]],
            [[$previous, $previous->endOfMonth()->startOfDay()]],
            TrendPeriod::Daily,
        );
    }

    /**
     * @param  list<string>  $platforms
     */
    public static function dateRange(CarbonImmutable $from, CarbonImmutable $to, array $platforms = []): self
    {
        return self::sameLengthComparison(self::MODE_DATE_RANGE, $from->startOfDay(), $to->startOfDay(), $platforms);
    }

    /**
     * One calendar month, compared with the month before it.
     *
     * @param  list<string>  $platforms
     */
    public static function month(int $year, int $month, array $platforms = []): self
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $previous = $start->subMonth();

        return new self(
            self::MODE_MONTH,
            $platforms,
            [[$start, $start->endOfMonth()->startOfDay()]],
            [[$previous, $previous->endOfMonth()->startOfDay()]],
            TrendPeriod::Daily,
            [$year],
            $month,
        );
    }

    /**
     * One or more full years, each compared with the year before it.
     *
     * @param  list<int>  $years
     * @param  list<string>  $platforms
     */
    public static function years(array $years, array $platforms = []): self
    {
        $years = array_values(array_unique($years));
        sort($years);

        return new self(
            self::MODE_YEARS,
            $platforms,
            array_map(fn (int $year) => self::yearBounds($year, $year), $years),
            self::mergeRanges(array_map(fn (int $year) => self::yearBounds($year - 1, $year - 1), $years)),
            TrendPeriod::Monthly,
            $years,
        );
    }

    /**
     * Every year from $fromYear to $toYear, compared with the same number of years before it.
     *
     * @param  list<string>  $platforms
     */
    public static function yearRange(int $fromYear, int $toYear, array $platforms = []): self
    {
        $length = $toYear - $fromYear + 1;

        return new self(
            self::MODE_YEAR_RANGE,
            $platforms,
            [self::yearBounds($fromYear, $toYear)],
            [self::yearBounds($fromYear - $length, $fromYear - 1)],
            TrendPeriod::Monthly,
            range($fromYear, $toYear),
        );
    }

    /**
     * The same filter applied to the comparison ranges, used to calculate the previous period.
     */
    public function previous(): self
    {
        return new self($this->mode, $this->platforms, $this->previousRanges, [], $this->defaultPeriod, $this->years, $this->month);
    }

    public function hasComparison(): bool
    {
        return $this->previousRanges !== [];
    }

    public function from(): CarbonImmutable
    {
        return $this->ranges[0][0];
    }

    public function to(): CarbonImmutable
    {
        return $this->ranges[array_key_last($this->ranges)][1];
    }

    /**
     * Several selected years are compared side by side instead of shown as one timeline.
     */
    public function comparesYears(): bool
    {
        return $this->mode === self::MODE_YEARS && count($this->years) > 1;
    }

    /**
     * Number of days covered by every range together.
     */
    public function days(): int
    {
        return array_sum(array_map(fn (array $range) => (int) $range[0]->diffInDays($range[1]) + 1, $this->ranges));
    }

    /**
     * The active filter as returned in every performance response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'date_from' => $this->from()->toDateString(),
            'date_to' => $this->to()->toDateString(),
            'platform' => count($this->platforms) === 1 ? $this->platforms[0] : null,
            'platforms' => $this->platforms,
            'years' => $this->mode === self::MODE_DATE_RANGE || $this->mode === self::MODE_CURRENT_MONTH ? [] : $this->years,
            'month' => $this->month,
            'ranges' => self::rangesToArray($this->ranges),
            ...($this->period === null ? [] : ['period' => $this->period->toArray()]),
        ];
    }

    /**
     * @return array{date_from: string|null, date_to: string|null, ranges: list<array{date_from: string, date_to: string}>}
     */
    public function previousToArray(): array
    {
        if (! $this->hasComparison()) {
            return ['date_from' => null, 'date_to' => null, 'ranges' => []];
        }

        $previous = $this->previous();

        return [
            'date_from' => $previous->from()->toDateString(),
            'date_to' => $previous->to()->toDateString(),
            'ranges' => self::rangesToArray($this->previousRanges),
        ];
    }

    /**
     * A single range compared with the range of the same length right before it.
     *
     * @param  list<string>  $platforms
     */
    private static function sameLengthComparison(string $mode, CarbonImmutable $from, CarbonImmutable $to, array $platforms): self
    {
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();

        return new self(
            $mode,
            $platforms,
            [[$from, $to]],
            [[$previousTo->subDays($days - 1), $previousTo]],
            TrendPeriod::Daily,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function yearBounds(int $fromYear, int $toYear): array
    {
        return [
            CarbonImmutable::create($fromYear, 1, 1)->startOfDay(),
            CarbonImmutable::create($toYear, 12, 31)->startOfDay(),
        ];
    }

    /**
     * Join ranges that touch each other, so consecutive years become one range.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function mergeRanges(array $ranges): array
    {
        $merged = [];

        foreach ($ranges as $range) {
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last][1]->addDay()->equalTo($range[0])) {
                $merged[$last][1] = $range[1];
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return list<array{date_from: string, date_to: string}>
     */
    private static function rangesToArray(array $ranges): array
    {
        return array_map(fn (array $range) => [
            'date_from' => $range[0]->toDateString(),
            'date_to' => $range[1]->toDateString(),
        ], $ranges);
    }
}
