<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\MarkazTransactionStatus;
use App\Enums\PeriodStateBasis;
use App\Enums\TransactionDateBasis;
use App\Repositories\DashboardTransactionRepository;
use App\Repositories\PlatformContributionRepository;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodWindow;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PlatformContributionService
{
    public function __construct(
        private PlatformContributionRepository $platformContributionRepository,
        private DashboardTransactionRepository $dashboardTransactions,
        private TransactionAggregationService $aggregations,
        private ComparisonSnapshotService $comparisonSnapshots,
    ) {}

    /**
     * Each platform's revenue, transactions, users, and share of total revenue for the selected filters.
     *
     * @return array<string, mixed>
     */
    public function contribution(PerformanceFilter $filter): array
    {
        $platforms = $this->platformContributionRepository
            ->totalsPerPlatform($filter)
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

        // The percentage is null (shown as N/A) when there is no revenue to divide by.
        $platforms = $platforms->map(fn (array $platform, int $index) => [
            'rank' => $index + 1,
            ...$platform,
            'contribution_percentage' => $totalRevenue > 0
                ? round($platform['revenue'] / $totalRevenue * 100, 1)
                : null,
        ]);

        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => $filter->toArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Tidak ada data kontribusi platform untuk filter yang dipilih.' : null,
            'totals' => [
                'revenue' => $totalRevenue,
                'transactions' => $totalTransactions,
                'users' => $platforms->sum('users'),
                'platforms' => $platforms->count(),
            ],
            'platforms' => $platforms->all(),
            'labels' => $platforms->pluck('name')->all(),
            'datasets' => [
                ['label' => 'Revenue', 'data' => $platforms->pluck('revenue')->all()],
            ],
        ];
    }

    /**
     * The shared per-platform transaction dataset (STEAM-299).
     *
     * Created group (markaz_created_at): total transactions incl. coupon claims, Success count, conversion,
     * and the five-status breakdown. Paid group (paid_date of a Success): success payments and revenue (net_nominal).
     * Contribution = a platform's non-claim success payments ÷ the same sum over the selected platforms.
     * revenue_comparison = the same revenue over the comparison range of the resolved period (FIX-04).
     *
     * Null means unknown coverage, never 0. A zero divisor gives a null contribution (shown as N/A).
     *
     * @return array<string, mixed>
     */
    public function transactionMetrics(PerformanceFilter $filter): array
    {
        $period = $filter->period ?? throw new InvalidArgumentException('The platform transaction metrics need a resolved period.');
        $active = $period->active;
        $comparison = $period->comparison;

        $platforms = $this->platformContributionRepository->platformsBySlugs($period->platforms);
        $platformIds = $platforms->map(fn (object $platform) => (int) $platform->id)->all();

        $activeRows = $platformIds === []
            ? []
            : $this->dashboardTransactions->contractRows($platformIds, $active->dateFrom, $active->dateTo->addDay());
        $comparisonRows = $platformIds === [] || $comparison === null
            ? []
            : $this->dashboardTransactions->contractRows($platformIds, $comparison->dateFrom, $comparison->dateTo->addDay());

        // An equivalent-hour comparison reads the snapshots recorded for its whole range at its cutoff, never the latest state (STEAM-299 revision).
        $recordedComparison = $comparison?->stateBasis === PeriodStateBasis::AtCutoff && $comparison->cutoffAt !== null && $platformIds !== []
            ? $this->dashboardTransactions->periodSnapshots($platformIds, $comparison->dateFrom, $comparison->dateTo)
            : [];

        $rows = $platforms->map(fn (object $platform) => $this->platformRow(
            $platform,
            $activeRows,
            $comparisonRows,
            $recordedComparison,
            $active,
            $comparison,
        ))->all();

        $totalSuccessPayments = $this->sumOrNull(array_column($rows, 'success_payments'));

        $rows = array_map(fn (array $row) => [
            ...$row,
            'contribution_percentage' => Ratio::percentage($row['success_payments'], $totalSuccessPayments),
        ], $rows);

        usort($rows, fn (array $a, array $b) => [$b['contribution_percentage'] ?? -1, $a['name']] <=> [$a['contribution_percentage'] ?? -1, $b['name']]);

        $rows = array_map(fn (array $row, int $index) => ['rank' => $index + 1, ...$row], $rows, array_keys($rows));

        $totalTransactions = $this->sumOrNull(array_column($rows, 'total_transactions'));
        $isEmpty = $totalTransactions === 0;

        return [
            'filter' => $filter->toArray(),
            'period' => $period->toArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Tidak ada data transaksi untuk filter yang dipilih.' : null,
            'totals' => [
                'total_transactions' => $totalTransactions,
                'created_success_transactions' => $this->sumOrNull(array_column($rows, 'created_success_transactions')),
                'success_payments' => $totalSuccessPayments,
                'revenue' => $this->sumOrNull(array_column($rows, 'revenue')),
                'revenue_comparison' => $this->sumOrNull(array_column($rows, 'revenue_comparison')),
                'platforms' => count($rows),
            ],
            'platforms' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $activeRows
     * @param  list<array<string, mixed>>  $comparisonRows
     * @param  list<array<string, mixed>>  $recordedComparison
     * @return array<string, mixed>
     */
    private function platformRow(
        object $platform,
        array $activeRows,
        array $comparisonRows,
        array $recordedComparison,
        PeriodWindow $active,
        ?PeriodWindow $comparison,
    ): array {
        $platformId = (int) $platform->id;
        $created = $this->metrics($activeRows, $platformId, $active, TransactionDateBasis::Created);
        $paid = $this->metrics($activeRows, $platformId, $active, TransactionDateBasis::Paid);
        $comparisonRevenue = $comparison === null
            ? null
            : $this->comparisonRevenue($platformId, $comparison, $comparisonRows, $recordedComparison);

        return [
            'slug' => $platform->slug,
            'name' => $platform->name,
            'total_transactions' => $created['total_checkouts'],
            'created_success_transactions' => $created['success_purchases'],
            'conversion_percentage' => $created['conversion_percentage'],
            'conversion_is_provisional' => $created['is_provisional'],
            'status_breakdown' => [
                ['key' => 'success', 'label' => 'Success', 'count' => $created['status_breakdown']['success']['count']],
                ['key' => 'pending', 'label' => 'Pending', 'count' => $created['status_breakdown']['pending']['count']],
                ['key' => 'unsuccessful', 'label' => 'Tidak Berhasil', 'count' => $created['unsuccessful_purchases']],
                ['key' => 'claim_coupon', 'label' => 'Klaim Kupon', 'count' => $created['claim_checkouts']],
                ['key' => 'unknown', 'label' => MarkazTransactionStatus::UNAVAILABLE_LABEL, 'count' => $created['unknown_status_count']],
            ],
            'unknown_status_count' => $created['unknown_status_count'],
            'success_payments' => $paid['success_payments'],
            'revenue' => $paid['revenue'] === null ? null : round($paid['revenue'], 2),
            'revenue_comparison' => $comparisonRevenue,
            'contribution_percentage' => null,
        ];
    }

    /**
     * The comparison revenue of one platform. A full-period comparison reads the latest state. An equivalent-hour
     * comparison reads the snapshot recorded for its whole date range at its cutoff, so every day of the range shows
     * the state at that hour, including the days before the last day. A missing, incomplete, or unavailable snapshot
     * gives null, never a value rebuilt from the latest state.
     *
     * @param  list<array<string, mixed>>  $comparisonRows
     * @param  list<array<string, mixed>>  $recordedComparison  The recorded snapshots of the comparison range.
     */
    private function comparisonRevenue(int $platformId, PeriodWindow $comparison, array $comparisonRows, array $recordedComparison): ?float
    {
        if ($comparison->stateBasis !== PeriodStateBasis::AtCutoff) {
            $revenue = $this->metrics($comparisonRows, $platformId, $comparison, TransactionDateBasis::Paid)['revenue'];

            return $revenue === null ? null : round($revenue, 2);
        }

        $cutoff = $comparison->cutoffAt;

        if ($comparison->availability === DataAvailability::Unavailable || $cutoff === null) {
            return null;
        }

        $recorded = $this->comparisonSnapshots->read(
            $recordedComparison,
            [$platformId],
            ComparisonSnapshotService::DATASET_TRANSACTIONS,
            $comparison->dateFrom,
            $comparison->dateTo,
            $cutoff,
        )['values']['revenue'] ?? null;

        if (! is_int($recorded) && ! is_float($recorded)) {
            return null;
        }

        return round((float) $recorded, 2);
    }

    /**
     * The shared aggregation of one platform, one basis, and one period window.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function metrics(array $rows, int $platformId, PeriodWindow $window, TransactionDateBasis $basis): array
    {
        return $this->aggregate($rows, $platformId, $window->dateFrom, $window->dateTo, $basis, $window->cutoffAt);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregate(array $rows, int $platformId, CarbonImmutable $from, CarbonImmutable $to, TransactionDateBasis $basis, ?CarbonImmutable $cutoff): array
    {
        return $this->aggregations->aggregate(
            $rows,
            new AggregationFilter([$platformId], $from, $to, $basis, $cutoff),
        )['metrics'];
    }

    /**
     * @param  list<int|float|null>  $values
     */
    private function sumOrNull(array $values): int|float|null
    {
        if ($values === [] || in_array(null, $values, true)) {
            return null;
        }

        return array_sum($values);
    }
}
