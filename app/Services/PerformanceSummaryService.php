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
     * The value is always the whole active period. When the comparison cannot be shown, the previous value,
     * change, and trend are null. When some active days have no equivalent date, the change only uses the
     * days that exist on both sides.
     *
     * @return array<string, mixed>
     */
    public function summarize(PerformanceFilter $filter): array
    {
        $current = $this->metrics($filter);
        $previous = $filter->canCompare() ? $this->metrics($filter->previous()) : null;
        [$changeCurrent, $changePrevious] = $previous === null ? [null, null] : $this->changeBasisMetrics($filter, $current, $previous);

        $isEmpty = $current['total_transactions'] === 0 && $current['total_downloaders'] === 0;

        return [
            'filter' => $filter->toArray(),
            'previous_period' => $filter->previousToArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'kpis' => collect($current)
                ->map(fn (int|float|null $value, string $key) => $previous === null
                    ? ['value' => $value, 'previous_value' => null, 'change_percentage' => null, 'trend' => null]
                    : [
                        'value' => $value,
                        'previous_value' => $previous[$key],
                        ...$this->changeFromPrevious($changeCurrent[$key], $changePrevious[$key]),
                    ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, int|float|null>  $current
     * @param  array<string, int|float|null>  $previous
     * @return array{0: array<string, int|float|null>, 1: array<string, int|float|null>}
     */
    private function changeBasisMetrics(PerformanceFilter $filter, array $current, array $previous): array
    {
        $basis = $filter->changeBasisFilters();

        return $basis === null
            ? [$current, $previous]
            : [$this->metrics($basis[0]), $this->metrics($basis[1])];
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
}
