<?php

namespace App\Support\Period;

use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use Carbon\CarbonImmutable;

/**
 * The period options chosen on the filter, before they are resolved into date ranges.
 */
final class PeriodSelection
{
    public const DATASET_TRANSACTIONS = 'transactions';

    public const DATASET_DOWNLOADERS = 'downloaders';

    public const DATASETS = [self::DATASET_TRANSACTIONS, self::DATASET_DOWNLOADERS];

    /**
     * @param  list<string>  $platforms  Platform slugs; empty means every platform.
     */
    public function __construct(
        public readonly PeriodPreset $preset,
        public readonly PeriodComparison $comparison = PeriodComparison::Auto,
        public readonly array $platforms = [],
        public readonly string $dataset = self::DATASET_TRANSACTIONS,
        public readonly ?CarbonImmutable $dateFrom = null,
        public readonly ?CarbonImmutable $dateTo = null,
        public readonly ?CarbonImmutable $comparisonFrom = null,
        public readonly ?CarbonImmutable $comparisonTo = null,
    ) {}
}
