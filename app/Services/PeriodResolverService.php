<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use App\Enums\PeriodStateBasis;
use App\Support\Period\ChangeBasis;
use App\Support\Period\PeriodCoverageSource;
use App\Support\Period\PeriodSelection;
use App\Support\Period\PeriodWindow;
use App\Support\Period\ResolvedPeriod;
use App\Support\TransactionAggregation\TransactionRecord;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Turns the filter options and the completeness metadata into the active period, the comparison period,
 * and the part of both used for the change. It only resolves dates and never reads transaction status.
 */
class PeriodResolverService
{
    public function __construct(private readonly PeriodCoverageSource $coverage) {}

    public function resolve(PeriodSelection $selection, ?CarbonImmutable $now = null): ResolvedPeriod
    {
        $this->assertValidDates($selection);

        $today = ($now ?? CarbonImmutable::now())->setTimezone(TransactionRecord::TIMEZONE)->startOfDay();
        $active = $this->activeWindow($selection, ...$this->activeDates($selection, $today));

        [$comparison, $changeBasis] = match (true) {
            $selection->comparison === PeriodComparison::Custom => $this->customComparison($selection, $active),
            $this->comparesFullPreviousMonth($selection, $active) => $this->fullPreviousMonth($selection, $active),
            default => $this->equivalentComparison($selection, $active),
        };

        return new ResolvedPeriod(
            $selection->preset,
            $selection->comparison,
            $selection->platforms,
            $selection->dataset,
            $active,
            $comparison,
            $changeBasis,
        );
    }

    private function assertValidDates(PeriodSelection $selection): void
    {
        if ($selection->preset === PeriodPreset::DateRange) {
            $this->assertOrdered($selection->dateFrom, $selection->dateTo, 'date_to', 'Tanggal akhir');
        }

        if ($selection->comparison === PeriodComparison::Custom) {
            $this->assertOrdered($selection->comparisonFrom, $selection->comparisonTo, 'comparison_to', 'Tanggal akhir pembanding');
        }
    }

    private function assertOrdered(?CarbonImmutable $from, ?CarbonImmutable $to, string $field, string $label): void
    {
        if ($from === null || $to === null) {
            throw ValidationException::withMessages([$field => "{$label} wajib diisi."]);
        }

        if ($to->startOfDay()->lt($from->startOfDay())) {
            throw ValidationException::withMessages([$field => "{$label} tidak boleh sebelum tanggal mulai."]);
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function activeDates(PeriodSelection $selection, CarbonImmutable $today): array
    {
        $yesterday = $today->subDay();
        $lastMonth = $today->startOfMonth()->subMonth();

        return match ($selection->preset) {
            PeriodPreset::Today => [$today, $today],
            PeriodPreset::Yesterday => [$yesterday, $yesterday],
            PeriodPreset::Last7Days, PeriodPreset::Last30Days, PeriodPreset::Last90Days => [
                $today->subDays($selection->preset->rollingDays()),
                $yesterday,
            ],
            PeriodPreset::ThisMonth => [$today->startOfMonth(), $today],
            PeriodPreset::LastMonth => [$lastMonth, $lastMonth->endOfMonth()->startOfDay()],
            PeriodPreset::LastYear => [$today->startOfYear(), $today],
            PeriodPreset::DateRange => [$this->day($selection->dateFrom), $this->day($selection->dateTo)],
        };
    }

    private function activeWindow(PeriodSelection $selection, CarbonImmutable $from, CarbonImmutable $to): PeriodWindow
    {
        $periodEnd = $to->addDay();
        $completeUntil = $this->combinedCutoff($selection, $from, $to);
        $availability = DataAvailability::forCoverage($completeUntil, $from, $periodEnd);
        $endAt = $availability === DataAvailability::Partial ? $completeUntil : $periodEnd;

        return new PeriodWindow(
            $from,
            $to,
            $from,
            $endAt,
            $availability,
            $availability === DataAvailability::Unavailable ? null : $endAt,
            $availability === DataAvailability::Unavailable ? null : PeriodStateBasis::Latest,
        );
    }

    /**
     * The earliest of the platforms' latest complete cutoffs; null when any platform has no complete record.
     */
    private function combinedCutoff(PeriodSelection $selection, CarbonImmutable $from, CarbonImmutable $to): ?CarbonImmutable
    {
        $combined = null;

        foreach ($this->coverage->completeCutoffs($selection->platforms, $selection->dataset, $from, $to) as $cutoffs) {
            if ($cutoffs === []) {
                return null;
            }

            $latest = array_reduce($cutoffs, fn (?CarbonImmutable $carry, CarbonImmutable $cutoff) => $carry?->max($cutoff) ?? $cutoff);
            $combined = $combined?->min($latest) ?? $latest;
        }

        return $combined;
    }

    /**
     * Automatic Bulan Lalu compares two whole calendar months, even when their lengths differ.
     */
    private function comparesFullPreviousMonth(PeriodSelection $selection, PeriodWindow $active): bool
    {
        return $selection->preset === PeriodPreset::LastMonth
            && $selection->comparison === PeriodComparison::Auto
            && $active->availability !== DataAvailability::Partial;
    }

    /**
     * @return array{0: PeriodWindow, 1: ChangeBasis|null}
     */
    private function fullPreviousMonth(PeriodSelection $selection, PeriodWindow $active): array
    {
        $comparison = $this->comparisonWindow($selection, $active, $active->dateFrom->subMonth(), $active->dateFrom);

        return [$comparison, $this->wholePeriodsBasis($active, $comparison)];
    }

    /**
     * Maps every active day onto its equivalent date. Active days without an equivalent date, at either end or
     * in the middle of the range, stay in the active total but are left out of the change on both sides.
     *
     * @return array{0: PeriodWindow|null, 1: ChangeBasis|null}
     */
    private function equivalentComparison(PeriodSelection $selection, PeriodWindow $active): array
    {
        $equivalent = $this->equivalentDay($selection, $active);
        $lastActiveDay = PeriodWindow::lastDay($active->endAt);
        $activeDays = [];
        $comparisonDays = [];

        for ($day = $active->dateFrom; $day->lte($lastActiveDay); $day = $day->addDay()) {
            if (($mapped = $equivalent($day)) !== null) {
                $activeDays[] = $day;
                $comparisonDays[] = $mapped;
            }
        }

        if ($activeDays === []) {
            return [null, null];
        }

        $keepsHour = end($activeDays)->eq($lastActiveDay) && ! $this->isMidnight($active->endAt);
        $comparisonEnd = $keepsHour
            ? end($comparisonDays)->setTimeFrom($active->endAt)
            : end($comparisonDays)->addDay();

        $comparison = $this->comparisonWindow($selection, $active, $comparisonDays[0], $comparisonEnd);

        return [$comparison, $this->changeBasis(
            $active,
            $comparison,
            $this->ranges($activeDays, $keepsHour ? $active->endAt : null),
            $this->ranges($comparisonDays, $keepsHour ? $comparisonEnd : null),
        )];
    }

    /**
     * Joins consecutive days into [start, exclusive end) ranges; $lastEnd replaces the end of the last range.
     *
     * @param  list<CarbonImmutable>  $days
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function ranges(array $days, ?CarbonImmutable $lastEnd): array
    {
        $ranges = [];

        foreach ($days as $day) {
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last][1]->eq($day)) {
                $ranges[$last][1] = $day->addDay();
            } else {
                $ranges[] = [$day, $day->addDay()];
            }
        }

        if ($lastEnd !== null) {
            $ranges[array_key_last($ranges)][1] = $lastEnd;
        }

        return $ranges;
    }

    /**
     * @return Closure(CarbonImmutable): ?CarbonImmutable
     */
    private function equivalentDay(PeriodSelection $selection, PeriodWindow $active): Closure
    {
        $sameDay = fn (CarbonImmutable $day, CarbonImmutable $mapped) => $mapped->day === $day->day ? $mapped : null;
        $previousMonth = fn (CarbonImmutable $day) => $sameDay($day, $day->subMonthNoOverflow());
        $previousYear = fn (CarbonImmutable $day) => $sameDay($day, $day->subYearNoOverflow());
        $sameLengthBefore = fn (CarbonImmutable $day) => $day->subDays($active->days());

        return match ($selection->comparison) {
            PeriodComparison::SamePeriodLastMonth => $previousMonth,
            PeriodComparison::SamePeriodLastYear => $previousYear,
            PeriodComparison::PreviousPeriod => $sameLengthBefore,
            default => match ($selection->preset) {
                PeriodPreset::ThisMonth, PeriodPreset::LastMonth => $previousMonth,
                PeriodPreset::LastYear => $previousYear,
                default => $sameLengthBefore,
            },
        };
    }

    /**
     * An equivalent hour needs a complete record at exactly that cutoff for every platform, because a missing
     * record is never rebuilt from the latest status. A full period reads the latest state.
     */
    private function comparisonWindow(PeriodSelection $selection, PeriodWindow $active, CarbonImmutable $from, CarbonImmutable $endAt): PeriodWindow
    {
        $to = PeriodWindow::lastDay($endAt);
        $isEquivalentHour = ! $this->isMidnight($endAt);

        if ($active->availability === DataAvailability::Unavailable) {
            return new PeriodWindow($from, $to, $from, $endAt, DataAvailability::Unavailable, null, null);
        }

        if ($isEquivalentHour) {
            $isRecorded = $this->hasCompleteRecordAt($selection, $from, $to, $endAt);

            return new PeriodWindow(
                $from,
                $to,
                $from,
                $endAt,
                $isRecorded ? DataAvailability::Partial : DataAvailability::Unavailable,
                $endAt,
                PeriodStateBasis::AtCutoff,
            );
        }

        $isComplete = DataAvailability::forCoverage($this->combinedCutoff($selection, $from, $to), $from, $endAt) === DataAvailability::Complete;

        return new PeriodWindow(
            $from,
            $to,
            $from,
            $endAt,
            $isComplete ? DataAvailability::Complete : DataAvailability::Unavailable,
            $isComplete ? $endAt : null,
            PeriodStateBasis::Latest,
        );
    }

    private function hasCompleteRecordAt(PeriodSelection $selection, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $cutoff): bool
    {
        $platforms = $this->coverage->completeCutoffs($selection->platforms, $selection->dataset, $from, $to);

        return $platforms !== [] && collect($platforms)->every(
            fn (array $cutoffs) => collect($cutoffs)->contains(fn (CarbonImmutable $recorded) => $recorded->eq($cutoff)),
        );
    }

    /**
     * @return array{0: PeriodWindow, 1: ChangeBasis|null}
     */
    private function customComparison(PeriodSelection $selection, PeriodWindow $active): array
    {
        $from = $this->day($selection->comparisonFrom);
        $to = $this->day($selection->comparisonTo);
        $periodEnd = $to->addDay();
        $completeUntil = $this->combinedCutoff($selection, $from, $to);
        $availability = DataAvailability::forCoverage($completeUntil, $from, $periodEnd);
        $endAt = $availability === DataAvailability::Partial ? $completeUntil : $periodEnd;

        $comparison = new PeriodWindow(
            $from,
            $to,
            $from,
            $endAt,
            $availability,
            $availability === DataAvailability::Unavailable ? null : $endAt,
            $this->isMidnight($endAt) ? PeriodStateBasis::Latest : PeriodStateBasis::AtCutoff,
        );

        return [$comparison, $this->wholePeriodsBasis($active, $comparison)];
    }

    /**
     * Compares both periods in full, even when their lengths differ.
     */
    private function wholePeriodsBasis(PeriodWindow $active, PeriodWindow $comparison): ?ChangeBasis
    {
        return $this->changeBasis(
            $active,
            $comparison,
            [[$active->startAt, $active->endAt]],
            [[$comparison->startAt, $comparison->endAt]],
        );
    }

    /**
     * Null when either side is unavailable, so no change is calculated.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $activeRanges
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $comparisonRanges
     */
    private function changeBasis(PeriodWindow $active, PeriodWindow $comparison, array $activeRanges, array $comparisonRanges): ?ChangeBasis
    {
        if ($active->availability === DataAvailability::Unavailable || $comparison->availability === DataAvailability::Unavailable) {
            return null;
        }

        $coversWhole = fn (array $ranges, PeriodWindow $window) => count($ranges) === 1
            && $ranges[0][0]->eq($window->startAt)
            && $ranges[0][1]->eq($window->endAt);

        return new ChangeBasis(
            $activeRanges,
            $comparisonRanges,
            ! $coversWhole($activeRanges, $active) || ! $coversWhole($comparisonRanges, $comparison),
        );
    }

    private function isMidnight(CarbonImmutable $moment): bool
    {
        return $moment->eq($moment->startOfDay());
    }

    private function day(CarbonImmutable $date): CarbonImmutable
    {
        return $date->shiftTimezone(TransactionRecord::TIMEZONE)->startOfDay();
    }
}
