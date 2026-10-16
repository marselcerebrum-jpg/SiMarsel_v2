<?php

namespace App\Services;

use App\Repositories\ProductPerformanceRepository;
use Carbon\CarbonImmutable;

class TopPackageService
{
    public const DEFAULT_LIMIT = 10;

    public const RANK_BY_REVENUE = 'revenue';

    public const RANK_BY_TRANSACTIONS = 'transactions';

    /**
     * Sort order and chart dataset label per ranking metric. Ties fall back to the other metric, then the product name.
     */
    private const RANKINGS = [
        self::RANK_BY_REVENUE => [
            'sort' => [['revenue', 'desc'], ['transactions', 'desc'], ['product', 'asc']],
            'label' => 'Total Pendapatan',
        ],
        self::RANK_BY_TRANSACTIONS => [
            'sort' => [['transactions', 'desc'], ['revenue', 'desc'], ['product', 'asc']],
            'label' => 'Jumlah Transaksi',
        ],
    ];

    /**
     * Markaz product names end with the active period, e.g. "Bimbel CPNS 2026 - Masa Aktif 1 Tahun".
     */
    private const ACTIVE_PERIOD_PATTERN = '/^(?<name>.*?)\s*-\s*Masa Aktif\s+(?<period>.+)$/iu';

    public function __construct(private ProductPerformanceRepository $productPerformanceRepository) {}

    /**
     * The packages with the highest revenue or transaction count for the selected platform, year, and optional month.
     *
     * @return array<string, mixed>
     */
    public function top(?string $platformSlug, int $year, ?int $month, int $limit, string $rankBy = self::RANK_BY_REVENUE): array
    {
        $ranking = self::RANKINGS[$rankBy];

        $from = CarbonImmutable::create($year, $month ?? 1, 1)->startOfDay();
        $to = $month ? $from->endOfMonth()->startOfDay() : $from->endOfYear()->startOfDay();

        $packages = $this->productPerformanceRepository
            ->totalsPerProduct($from, $to, $platformSlug)
            ->map(fn (object $row) => [
                'product' => $row->product,
                ...$this->splitProduct($row->product),
                'revenue' => round((float) $row->revenue, 2),
                'transactions' => (int) $row->transactions,
            ])
            ->sortBy($ranking['sort'])
            ->values();

        $totals = [
            'packages' => $packages->count(),
            'revenue' => round($packages->sum('revenue'), 2),
            'transactions' => $packages->sum('transactions'),
        ];

        $top = $packages
            ->take($limit)
            ->map(fn (array $package, int $index) => [
                'rank' => $index + 1,
                ...$package,
                'contribution_percentage' => $totals[$rankBy] > 0
                    ? round($package[$rankBy] / $totals[$rankBy] * 100, 2)
                    : 0,
            ]);

        $isEmpty = $packages->isEmpty();

        return [
            'filter' => [
                'platform' => $platformSlug,
                'year' => $year,
                'month' => $month,
                'limit' => $limit,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'rank_by' => $rankBy,
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => $totals,
            'packages' => $top->all(),
            'labels' => $top->map(fn (array $package) => $this->label($package))->all(),
            'datasets' => [
                ['label' => $ranking['label'], 'data' => $top->pluck($rankBy)->all()],
            ],
        ];
    }

    /**
     * @return array{name: string|null, active_period: string|null}
     */
    private function splitProduct(?string $product): array
    {
        if ($product !== null && preg_match(self::ACTIVE_PERIOD_PATTERN, trim($product), $matches)) {
            return ['name' => $matches['name'], 'active_period' => $matches['period']];
        }

        return ['name' => $product, 'active_period' => null];
    }

    /**
     * Chart label that stays unique when one package is sold with several active periods.
     *
     * @param  array{name: string|null, active_period: string|null}  $package
     */
    private function label(array $package): string
    {
        $name = $package['name'] ?? 'Tanpa nama paket';

        return $package['active_period'] ? "{$name} ({$package['active_period']})" : $name;
    }
}
