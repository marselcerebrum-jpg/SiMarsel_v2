<?php

namespace App\Support\Period;

use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use App\Support\TransactionAggregation\TransactionRecord;

/**
 * The single period contract given to every query and export.
 * The comparison is null when no date of the active period has an equivalent date;
 * the change basis is null when the change cannot be calculated.
 */
final class ResolvedPeriod
{
    /**
     * @param  list<string>  $platforms
     */
    public function __construct(
        public readonly PeriodPreset $preset,
        public readonly PeriodComparison $comparisonMode,
        public readonly array $platforms,
        public readonly string $dataset,
        public readonly PeriodWindow $active,
        public readonly ?PeriodWindow $comparison,
        public readonly ?ChangeBasis $changeBasis,
    ) {}

    public function durationsDiffer(): bool
    {
        return $this->comparison !== null && $this->active->days() !== $this->comparison->days();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timezone' => TransactionRecord::TIMEZONE,
            'preset' => $this->preset->value,
            'comparison_mode' => $this->comparisonMode->value,
            'platforms' => $this->platforms,
            'dataset' => $this->dataset,
            'active' => $this->active->toArray(),
            'comparison' => $this->comparison?->toArray(),
            'change_basis' => $this->changeBasis?->toArray(),
            'durations_differ' => $this->durationsDiffer(),
        ];
    }
}
