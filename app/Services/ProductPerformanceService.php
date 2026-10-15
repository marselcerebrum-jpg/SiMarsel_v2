<?php

namespace App\Services;

use App\Repositories\ProductPerformanceRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ProductPerformanceService
{
    public function __construct(private ProductPerformanceRepository $productPerformanceRepository) {}

    /**
     * Each product's performance for the selected platform, year, and optional month.
     *
     * @return array<string, mixed>
     */
    public function summarize(?string $platformSlug, int $year, ?int $month): array
    {
        $from = CarbonImmutable::create($year, $month ?? 1, 1)->startOfDay();
        $to = $month ? $from->endOfMonth()->startOfDay() : $from->endOfYear()->startOfDay();

        $products = $this->productPerformanceRepository
            ->totalsPerProduct($from, $to, $platformSlug)
            ->map(fn (object $row) => [
                'name' => $row->product,
                'revenue' => round((float) $row->revenue, 2),
                'transactions' => (int) $row->transactions,
                'new_users' => (int) $row->new_users,
                'repeat_orders' => (int) $row->repeat_orders,
                'users' => (int) $row->users,
            ])
            ->sortBy([['revenue', 'desc'], ['transactions', 'desc'], ['name', 'asc']])
            ->values();

        $totalRevenue = round($products->sum('revenue'), 2);
        $totalTransactions = $products->sum('transactions');

        $products = $products->map(fn (array $product) => [
            ...$product,
            'contribution_percentage' => $totalRevenue > 0
                ? round($product['revenue'] / $totalRevenue * 100, 2)
                : 0,
        ]);

        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => [
                'platform' => $platformSlug,
                'year' => $year,
                'month' => $month,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'products' => $products->count(),
                'revenue' => $totalRevenue,
                'transactions' => $totalTransactions,
                'new_users' => $products->sum('new_users'),
                'repeat_orders' => $products->sum('repeat_orders'),
            ],
            'products' => $products->all(),
            'labels' => $products->pluck('name')->all(),
            'datasets' => [
                ['label' => 'Revenue', 'data' => $products->pluck('revenue')->all()],
            ],
        ];
    }

    /**
     * Options for the platform, year, and month filters, limited to periods that have successful transactions.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(?string $platformSlug): array
    {
        $periods = $this->productPerformanceRepository
            ->successfulTransactionDates($platformSlug)
            ->map(fn (CarbonInterface $date) => ['year' => $date->year, 'month' => $date->month])
            ->unique(fn (array $period) => $period['year'].'-'.$period['month']);

        $months = $periods
            ->groupBy('year')
            ->sortKeysDesc()
            ->map(fn (Collection $periods) => $periods->pluck('month')->sort()->values()->all());

        $latestYear = $months->keys()->first();

        return [
            'platform' => $platformSlug,
            'platforms' => $this->productPerformanceRepository->platforms()
                ->map(fn (object $platform) => ['slug' => $platform->slug, 'name' => $platform->name])
                ->all(),
            'years' => $months->keys()->all(),
            'months' => (object) $months->all(),
            'default' => [
                'year' => $latestYear,
                'month' => $latestYear ? last($months[$latestYear]) : null,
            ],
        ];
    }
}
