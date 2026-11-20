<?php

namespace App\Support\Period;

use Carbon\CarbonImmutable;

/**
 * Completeness metadata read by the period resolver.
 */
interface PeriodCoverageSource
{
    /**
     * The cutoffs of the complete records for exactly this dataset and inclusive date range.
     * Returns one entry per selected platform, with an empty list when a platform has no complete record.
     *
     * @param  list<string>  $platforms  Platform slugs; empty means every platform.
     * @return array<string, list<CarbonImmutable>>
     */
    public function completeCutoffs(array $platforms, string $dataset, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array;
}
