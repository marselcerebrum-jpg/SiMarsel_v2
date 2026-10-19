<?php

namespace App\Services;

use App\Repositories\PerformanceRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\PerformanceFilter;

class PerformanceSummaryService
{
    use ComparesWithPreviousValue;

    public function __construct(private PerformanceRepository $performanceRepository) {}

    /**
     * Build the KPI summary for the filter and compare it with the filter's previous period.
     *
     * @return array<string, mixed>
     */
    public function summarize(PerformanceFilter $filter): array
    {
        $current = $this->metrics($filter);
        $previous = $this->metrics($filter->previous());

        $isEmpty = $current['total_transactions'] === 0 && $current['total_downloaders'] === 0;

        return [
            'filter' => $filter->toArray(),
            'previous_period' => $filter->previousToArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'kpis' => collect($current)
                ->map(fn (int|float|null $value, string $key) => $this->compare($value, $previous[$key]))
                ->all(),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function metrics(PerformanceFilter $filter): array
    {
        $totals = $this->performanceRepository->transactionTotals($filter);
        $downloaders = $this->performanceRepository->totalDownloaders($filter);

        return [
            'total_revenue' => round($totals['revenue'], 2),
            'total_transactions' => $totals['transactions'],
            'total_downloaders' => $downloaders,
            'unique_users' => $this->performanceRepository->countUniqueCustomers($filter),
            'new_users' => $totals['new_users'],
            'repeat_orders' => $totals['repeat_orders'],
            'average_order_value' => $totals['transactions'] > 0
                ? round($totals['revenue'] / $totals['transactions'], 2)
                : null,
            'conversion_rate' => $downloaders > 0
                ? round($totals['transactions'] / $downloaders * 100, 2)
                : null,
        ];
    }

    /**
     * @return array{value: int|float|null, previous_value: int|float|null, change_percentage: float|null, trend: string}
     */
    private function compare(int|float|null $current, int|float|null $previous): array
    {
        return [
            'value' => $current,
            'previous_value' => $previous,
            ...$this->changeFromPrevious($current, $previous),
        ];
    }
}
