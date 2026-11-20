<?php

namespace App\Support\Period;

use App\Enums\DataAvailability;
use App\Enums\PeriodStateBasis;
use Carbon\CarbonImmutable;

/**
 * One resolved period in WIB: its inclusive dates, its [startAt, endAt) bounds, and the data limit.
 */
final class PeriodWindow
{
    public function __construct(
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly CarbonImmutable $startAt,
        public readonly CarbonImmutable $endAt,
        public readonly DataAvailability $availability,
        public readonly ?CarbonImmutable $cutoffAt,
        public readonly ?PeriodStateBasis $stateBasis,
    ) {}

    /**
     * The last calendar day that an exclusive end reaches.
     */
    public static function lastDay(CarbonImmutable $endAt): CarbonImmutable
    {
        $day = $endAt->startOfDay();

        return $endAt->eq($day) ? $day->subDay() : $day;
    }

    /**
     * Number of calendar days in the inclusive date range.
     */
    public function days(): int
    {
        return (int) $this->dateFrom->diffInDays($this->dateTo) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
            'start_at' => $this->startAt->toIso8601String(),
            'end_at' => $this->endAt->toIso8601String(),
            'days' => $this->days(),
            'availability' => $this->availability->value,
            'cutoff_at' => $this->cutoffAt?->toIso8601String(),
            'state_basis' => $this->stateBasis?->value,
        ];
    }
}
