<?php

namespace App\Services;

use App\Repositories\PackageDetailRepository;
use Carbon\CarbonImmutable;

class PackageDetailService
{
    /**
     * Export column headers, in the same order as each exported row.
     */
    public const EXPORT_HEADERS = ['No', 'Paket', 'Revenue', 'Trx', 'Low Price', 'Avg Price', 'High Price', 'Low Trx', 'Avg Trx', 'High Trx'];

    public function __construct(private PackageDetailRepository $packageDetailRepository) {}

    /**
     * Price and daily transaction statistics per package for the selected platform, year, optional month, and search.
     *
     * @return array<string, mixed>
     */
    public function detail(?string $platformSlug, int $year, ?int $month, ?string $search): array
    {
        $from = CarbonImmutable::create($year, $month ?? 1, 1)->startOfDay();
        $to = $month ? $from->endOfMonth()->startOfDay() : $from->endOfYear()->startOfDay();

        $prices = $this->packageDetailRepository->priceTotalsPerPackage($from, $to, $platformSlug, $search);
        $daily = $this->packageDetailRepository->dailyTransactionRangePerPackage($from, $to, $platformSlug, $search);

        $packages = $prices
            ->map(function (object $row, string $key) use ($daily) {
                $revenue = (float) $row->revenue;
                $transactions = (int) $row->transactions;
                $days = $daily->get($key);
                $activeDays = (int) ($days->active_days ?? 0);

                return [
                    'name' => $row->product,
                    'revenue' => round($revenue, 2),
                    'transactions' => $transactions,
                    'low_price' => round((float) $row->low_price, 2),
                    'avg_price' => round($revenue / $transactions, 2),
                    'high_price' => round((float) $row->high_price, 2),
                    'low_trx' => (int) ($days->low_trx ?? 0),
                    'avg_trx' => $activeDays > 0 ? round($transactions / $activeDays, 2) : 0,
                    'high_trx' => (int) ($days->high_trx ?? 0),
                    'active_days' => $activeDays,
                ];
            })
            ->sortBy([['revenue', 'desc'], ['transactions', 'desc'], ['name', 'asc']])
            ->values();

        $isEmpty = $packages->isEmpty();

        return [
            'filter' => [
                'platform' => $platformSlug,
                'year' => $year,
                'month' => $month,
                'search' => $search,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'totals' => [
                'packages' => $packages->count(),
                'revenue' => round($packages->sum('revenue'), 2),
                'transactions' => $packages->sum('transactions'),
            ],
            'packages' => $packages->all(),
        ];
    }

    /**
     * Rows for the CSV export, numbered in the same order as the API response.
     *
     * @param  list<array<string, mixed>>  $packages
     * @return list<list<int|float|string>>
     */
    public function exportRows(array $packages): array
    {
        return collect($packages)
            ->values()
            ->map(fn (array $package, int $index) => [
                $index + 1,
                $package['name'] ?? 'Tanpa nama paket',
                $package['revenue'],
                $package['transactions'],
                $package['low_price'],
                $package['avg_price'],
                $package['high_price'],
                $package['low_trx'],
                $package['avg_trx'],
                $package['high_trx'],
            ])
            ->all();
    }

    /**
     * File name that describes the filter, e.g. detail-performa-paket-app-a-2026-09.csv.
     *
     * @param  array{platform: string|null, year: int, month: int|null}  $filter
     */
    public function exportFileName(array $filter): string
    {
        $parts = array_filter([
            'detail-performa-paket',
            $filter['platform'] ?? 'semua-platform',
            $filter['year'],
            $filter['month'] ? str_pad((string) $filter['month'], 2, '0', STR_PAD_LEFT) : null,
        ]);

        return implode('-', $parts).'.csv';
    }
}
