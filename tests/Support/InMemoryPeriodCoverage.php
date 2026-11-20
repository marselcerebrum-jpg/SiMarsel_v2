<?php

namespace Tests\Support;

use App\Support\Period\PeriodCoverageSource;
use App\Support\TransactionAggregation\TransactionRecord;
use Carbon\CarbonImmutable;

/**
 * Completeness metadata fixture, with the same lookup scope as dashboard_snapshots.
 */
class InMemoryPeriodCoverage implements PeriodCoverageSource
{
    /** @var list<array{platform: string, dataset: string, date_from: string, date_to: string, cutoff_at: string, is_complete: bool}> */
    private array $records = [];

    /**
     * @param  list<string>  $knownPlatforms  The platforms an empty selection stands for.
     */
    public function __construct(private readonly array $knownPlatforms = ['app-a']) {}

    public function record(
        string $dateFrom,
        string $dateTo,
        string $cutoffAt,
        string $platform = 'app-a',
        string $dataset = 'transactions',
        bool $isComplete = true,
    ): self {
        $this->records[] = [
            'platform' => $platform,
            'dataset' => $dataset,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'cutoff_at' => $cutoffAt,
            'is_complete' => $isComplete,
        ];

        return $this;
    }

    public function completeCutoffs(array $platforms, string $dataset, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        $cutoffs = array_fill_keys($platforms === [] ? $this->knownPlatforms : $platforms, []);

        foreach ($this->records as $record) {
            if (array_key_exists($record['platform'], $cutoffs)
                && $record['is_complete']
                && $record['dataset'] === $dataset
                && $record['date_from'] === $dateFrom->toDateString()
                && $record['date_to'] === $dateTo->toDateString()) {
                $cutoffs[$record['platform']][] = CarbonImmutable::parse($record['cutoff_at'])->setTimezone(TransactionRecord::TIMEZONE);
            }
        }

        return $cutoffs;
    }
}
