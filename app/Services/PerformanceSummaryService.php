<?php

namespace App\Services;

use App\Repositories\PerformanceRepository;
use Carbon\CarbonImmutable;

class PerformanceSummaryService
{
    public const TREND_UP = 'up';

    public const TREND_DOWN = 'down';

    public const TREND_SAME = 'same';

    public function __construct(private PerformanceRepository $performanceRepository) {}

    /**
     * Build the KPI summary for the period and compare it with the previous period of the same length.
     *
     * @return array<string, mixed>
     */
    public function summarize(CarbonImmutable $from, CarbonImmutable $to, ?string $platformSlug): array
    {
        $platformId = $platformSlug ? $this->performanceRepository->findPlatformIdBySlug($platformSlug) : null;

        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $current = $this->metrics($from, $to, $platformId);
        $previous = $this->metrics($previousFrom, $previousTo, $platformId);

        $isEmpty = $current['total_transactions'] === 0 && $current['total_downloaders'] === 0;

        return [
            'filter' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'platform' => $platformSlug,
            ],
            'previous_period' => [
                'date_from' => $previousFrom->toDateString(),
                'date_to' => $previousTo->toDateString(),
            ],
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
    private function metrics(CarbonImmutable $from, CarbonImmutable $to, ?int $platformId): array
    {
        $totals = $this->performanceRepository->transactionTotals($from, $to, $platformId);
        $downloaders = $this->performanceRepository->totalDownloaders($from, $to, $platformId);

        return [
            'total_revenue' => round($totals['revenue'], 2),
            'total_transactions' => $totals['transactions'],
            'total_downloaders' => $downloaders,
            'unique_users' => $this->performanceRepository->countUniqueCustomers($from, $to, $platformId),
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
        $currentValue = $current ?? 0;
        $previousValue = $previous ?? 0;

        return [
            'value' => $current,
            'previous_value' => $previous,
            'change_percentage' => $previousValue == 0
                ? null
                : round(($currentValue - $previousValue) / $previousValue * 100, 2),
            'trend' => match ($currentValue <=> $previousValue) {
                1 => self::TREND_UP,
                -1 => self::TREND_DOWN,
                default => self::TREND_SAME,
            },
        ];
    }
}
