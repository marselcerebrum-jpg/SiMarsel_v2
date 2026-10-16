<?php

namespace App\Services;

use App\Repositories\ProductOverviewRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ProductOverviewService
{
    public const DEFAULT_LIMIT = 10;

    public const UNNAMED_PACKAGE = 'Tanpa nama paket';

    /**
     * Markaz product names end with the active period, e.g. "Bimbel CPNS 2026 - Masa Aktif 1 Tahun".
     */
    private const ACTIVE_PERIOD_PATTERN = '/^(?<name>.*?)\s*-\s*Masa Aktif\s+(?<period>.+)$/iu';

    public function __construct(private ProductOverviewRepository $productOverviewRepository) {}

    /**
     * Options for the Source App, Year, and Month filters, limited to periods that have successful transactions.
     *
     * @return array{platforms: list<array{slug: string, name: string}>, years: list<int>, months: array<int, list<int>>}
     */
    public function filters(?string $platformSlug): array
    {
        $months = $this->productOverviewRepository
            ->transactionDates($platformSlug)
            ->map(fn (CarbonInterface $date) => ['year' => $date->year, 'month' => $date->month])
            ->unique(fn (array $period) => $period['year'].'-'.$period['month'])
            ->groupBy('year')
            ->sortKeysDesc()
            ->map(fn (Collection $periods) => $periods->pluck('month')->sort()->values()->all());

        return [
            'platforms' => $this->productOverviewRepository->platforms()
                ->map(fn (object $platform) => ['slug' => $platform->slug, 'name' => $platform->name])
                ->all(),
            // The page always needs a year to select, so fall back to the current one when there is no data yet.
            'years' => $months->isEmpty() ? [CarbonImmutable::now()->year] : $months->keys()->all(),
            'months' => $months->all(),
        ];
    }

    /**
     * Packages with the highest total revenue.
     *
     * @return list<array<string, mixed>>
     */
    public function topByRevenue(?string $platformSlug, int $year, ?int $month, int $limit): array
    {
        return $this->topPackages($platformSlug, $year, $month)
            ->sortBy([['revenue', 'desc'], ['transactions', 'desc'], ['product', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Packages with the highest number of transactions.
     *
     * @return list<array<string, mixed>>
     */
    public function topByVolume(?string $platformSlug, int $year, ?int $month, int $limit): array
    {
        return $this->topPackages($platformSlug, $year, $month)
            ->sortBy([['transactions', 'desc'], ['revenue', 'desc'], ['product', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Price and daily transaction statistics per package, ordered by revenue.
     *
     * @return list<array<string, mixed>>
     */
    public function performance(?string $platformSlug, int $year, ?int $month): array
    {
        [$from, $to] = $this->period($year, $month);

        $daily = $this->productOverviewRepository->dailyTransactionRangePerProduct($from, $to, $platformSlug);

        return $this->productOverviewRepository
            ->totalsPerProduct($from, $to, $platformSlug)
            ->map(function (object $row, string $key) use ($daily) {
                $revenue = (float) $row->revenue;
                $transactions = (int) $row->transactions;
                $days = $daily->get($key);
                $activeDays = (int) ($days->active_days ?? 0);

                return [
                    'name' => $row->product ?? self::UNNAMED_PACKAGE,
                    'revenue' => round($revenue, 2),
                    'trx' => $transactions,
                    'low_price' => round((float) $row->low_price, 2),
                    'low_trx' => (int) ($days->low_trx ?? 0),
                    'avg_price' => round($revenue / $transactions, 2),
                    'avg_trx' => $activeDays > 0 ? round($transactions / $activeDays, 2) : 0,
                    'high_price' => round((float) $row->high_price, 2),
                    'high_trx' => (int) ($days->high_trx ?? 0),
                ];
            })
            ->sortBy([['revenue', 'desc'], ['trx', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Revenue and transactions per package, with the product name split into package name and active period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topPackages(?string $platformSlug, int $year, ?int $month): Collection
    {
        [$from, $to] = $this->period($year, $month);

        return $this->productOverviewRepository
            ->totalsPerProduct($from, $to, $platformSlug)
            ->values()
            ->map(fn (object $row) => [
                ...$this->splitProduct($row->product),
                'product' => $row->product ?? self::UNNAMED_PACKAGE,
                'revenue' => round((float) $row->revenue, 2),
                'transactions' => (int) $row->transactions,
            ]);
    }

    /**
     * @return array{name: string, active_period: string|null}
     */
    private function splitProduct(?string $product): array
    {
        if ($product !== null && preg_match(self::ACTIVE_PERIOD_PATTERN, trim($product), $matches)) {
            return ['name' => $matches['name'], 'active_period' => $matches['period']];
        }

        return ['name' => $product ?? self::UNNAMED_PACKAGE, 'active_period' => null];
    }

    /**
     * A whole month when one is selected, otherwise the whole year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(int $year, ?int $month): array
    {
        $from = CarbonImmutable::create($year, $month ?? 1, 1)->startOfDay();

        return [$from, $month ? $from->endOfMonth()->startOfDay() : $from->endOfYear()->startOfDay()];
    }
}
