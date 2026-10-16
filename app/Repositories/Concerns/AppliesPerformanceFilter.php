<?php

namespace App\Repositories\Concerns;

use App\Models\Platform;
use App\Support\PerformanceFilter;
use Illuminate\Contracts\Database\Query\Builder;

trait AppliesPerformanceFilter
{
    /**
     * Limit the query to the selected platforms and to the filter's date ranges.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    protected function applyPerformanceFilter(Builder $query, PerformanceFilter $filter, string $dateColumn, string $platformColumn): Builder
    {
        return $this->whereInPerformancePeriod($query, $filter, $dateColumn)
            ->when($filter->platforms !== [], fn (Builder $query) => $query->whereIn(
                $platformColumn,
                Platform::query()->whereIn('slug', $filter->platforms)->select('id'),
            ));
    }

    /**
     * Keep rows whose date falls in any of the filter's ranges; several years can be non-consecutive.
     * Full-day bounds so date columns compare correctly on every database driver.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    protected function whereInPerformancePeriod(Builder $query, PerformanceFilter $filter, string $dateColumn): Builder
    {
        return $query->where(function (Builder $query) use ($filter, $dateColumn) {
            foreach ($filter->ranges as [$from, $to]) {
                $query->orWhereBetween($dateColumn, [
                    $from->startOfDay()->toDateTimeString(),
                    $to->endOfDay()->toDateTimeString(),
                ]);
            }
        });
    }
}
