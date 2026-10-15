<?php

namespace App\Services;

use App\Repositories\PlatformContributionRepository;
use Carbon\CarbonImmutable;

class PlatformContributionService
{
    public function __construct(private PlatformContributionRepository $platformContributionRepository) {}

    /**
     * Each platform's revenue, transactions, users, and share of total revenue for the selected filters.
     *
     * @return array<string, mixed>
     */
    public function contribution(CarbonImmutable $from, CarbonImmutable $to, ?string $platformSlug): array
    {
        $platforms = $this->platformContributionRepository
            ->totalsPerPlatform($from, $to, $platformSlug)
            ->map(fn (object $row) => [
                'slug' => $row->slug,
                'name' => $row->name,
                'revenue' => round((float) $row->revenue, 2),
                'transactions' => (int) $row->transactions,
                'users' => (int) $row->users,
            ])
            ->sortBy([['revenue', 'desc'], ['name', 'asc']])
            ->values();

        $totalRevenue = round($platforms->sum('revenue'), 2);
        $totalTransactions = $platforms->sum('transactions');

        $platforms = $platforms->map(fn (array $platform) => [
            ...$platform,
            'contribution_percentage' => $totalRevenue > 0
                ? round($platform['revenue'] / $totalRevenue * 100, 2)
                : 0,
        ]);

        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'platform' => $platformSlug,
            ],
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'revenue' => $totalRevenue,
                'transactions' => $totalTransactions,
                'users' => $platforms->sum('users'),
            ],
            'platforms' => $platforms->all(),
            'labels' => $platforms->pluck('name')->all(),
            'datasets' => [
                ['label' => 'Revenue', 'data' => $platforms->pluck('revenue')->all()],
            ],
        ];
    }
}
