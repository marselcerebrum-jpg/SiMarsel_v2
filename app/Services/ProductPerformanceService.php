<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\TransactionDateBasis;
use App\Repositories\DashboardTransactionRepository;
use App\Support\Period\ResolvedPeriod;
use App\Support\ProductPerformanceQuery;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;

/**
 * FIX-11-BE-01: Performa Produk on the Dashboard. One row per product per platform with Total Transaksi,
 * Transaksi Berhasil, Pending, Tidak Berhasil, Omzet, Kontribusi Transaksi, and Kontribusi Omzet.
 *
 * Every value comes from the shared transaction aggregation (FIX-02-BE-02) over the active period of the
 * main filter: the counts and Kontribusi Transaksi follow the created date, Omzet and Kontribusi Omzet follow
 * the last valid payment date of a Success and sum net_nominal. Coupon claims count in Total only, and group
 * members never count. The contribution divisors are the totals of every product in the main filter, so the
 * table's search, sort, and page never change them. table() and all() build the same dataset; all() is the
 * full scope used by the Dashboard export.
 */
class ProductPerformanceService
{
    public const UNNAMED_PRODUCT = 'Produk Tanpa Nama';

    public const DEFAULT_SORT = 'revenue';

    public const SORTS = [
        'product',
        'total_transactions',
        'success_purchases',
        'pending_purchases',
        'unsuccessful_purchases',
        'revenue',
        'transaction_contribution',
        'revenue_contribution',
    ];

    public const CONTRIBUTION_AVAILABLE = 'available';

    public const CONTRIBUTION_ZERO_DIVISOR = 'zero_divisor';

    public const CONTRIBUTION_UNAVAILABLE = 'unavailable';

    /** The table columns in their required order. */
    private const COLUMNS = [
        ['key' => 'product', 'label' => 'Produk', 'unit' => null, 'date_basis' => null],
        ['key' => 'total_transactions', 'label' => 'Total Transaksi', 'unit' => 'count', 'date_basis' => 'created'],
        ['key' => 'success_purchases', 'label' => 'Transaksi Berhasil', 'unit' => 'count', 'date_basis' => 'created'],
        ['key' => 'pending_purchases', 'label' => 'Pending', 'unit' => 'count', 'date_basis' => 'created'],
        ['key' => 'unsuccessful_purchases', 'label' => 'Tidak Berhasil', 'unit' => 'count', 'date_basis' => 'created'],
        ['key' => 'revenue', 'label' => 'Omzet', 'unit' => 'currency', 'date_basis' => 'paid'],
        ['key' => 'transaction_contribution', 'label' => 'Kontribusi Transaksi (%)', 'unit' => 'percentage', 'date_basis' => 'created'],
        ['key' => 'revenue_contribution', 'label' => 'Kontribusi Omzet (%)', 'unit' => 'percentage', 'date_basis' => 'paid'],
    ];

    public function __construct(
        private DashboardTransactionRepository $dashboardTransactions,
        private TransactionAggregationService $aggregations,
    ) {}

    /**
     * One page of the table after its local search and sort. Totals and contributions keep the main filter scope.
     *
     * @return array<string, mixed>
     */
    public function table(ResolvedPeriod $period, ProductPerformanceQuery $query): array
    {
        $dataset = $this->dataset($period);
        $rows = $this->sorted($this->searched($dataset['rows'], $query->search), $query->sort, $query->direction);
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $query->perPage));
        $offset = ($query->page - 1) * $query->perPage;
        $pageRows = array_slice($rows, $offset, $query->perPage);

        return [
            ...$dataset,
            'query' => $query->toArray(),
            'rows' => $pageRows,
            'pagination' => [
                'current_page' => $query->page,
                'per_page' => $query->perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $pageRows === [] ? null : $offset + 1,
                'to' => $pageRows === [] ? null : $offset + count($pageRows),
            ],
        ];
    }

    /**
     * Every product in the main filter, without the table's search or pages, in the default order. For the
     * Dashboard export (FIX-14).
     *
     * @return array<string, mixed>
     */
    public function all(ResolvedPeriod $period): array
    {
        $dataset = $this->dataset($period);

        return [...$dataset, 'rows' => $this->sorted($dataset['rows'], self::DEFAULT_SORT, 'desc')];
    }

    /**
     * The key used to group a product within its platform: case- and whitespace-insensitive.
     */
    public static function productKey(mixed $product): string
    {
        return mb_strtolower(self::displayName($product));
    }

    private static function displayName(mixed $product): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $product));
    }

    /**
     * @return array<string, mixed>
     */
    private function dataset(ResolvedPeriod $period): array
    {
        $window = $period->active;
        $platformIds = $this->dashboardTransactions->platformIds($period->platforms);
        $unavailable = $platformIds === [] || $window->availability === DataAvailability::Unavailable;
        $filter = fn (TransactionDateBasis $basis) => AggregationFilter::forWindow($platformIds, $window->dateFrom, $window->dateTo->addDay(), $basis, $window->cutoffAt);
        $availability = $unavailable ? DataAvailability::Unavailable : $filter(TransactionDateBasis::Created)->availability();

        $base = [
            'period' => $period->toArray(),
            'active' => [
                'availability' => $availability->value,
                'as_of' => $unavailable ? null : $filter(TransactionDateBasis::Created)->cutoff()?->toIso8601String(),
            ],
            'columns' => self::COLUMNS,
        ];

        if ($availability === DataAvailability::Unavailable) {
            return [
                ...$base,
                'is_empty' => false,
                'message' => 'Data periode aktif belum tersedia.',
                'totals' => ['products' => null, 'total_transactions' => null, 'success_purchases' => null, 'revenue' => null],
                'contribution' => ['transaction' => self::CONTRIBUTION_UNAVAILABLE, 'revenue' => self::CONTRIBUTION_UNAVAILABLE],
                'rows' => [],
            ];
        }

        $transactions = $this->dashboardTransactions->contractRows($platformIds, $window->dateFrom, $window->dateTo->addDay(), ['product']);
        $created = $filter(TransactionDateBasis::Created);
        $paid = $filter(TransactionDateBasis::Paid);

        // The divisors: every product in the main filter, through the same shared aggregation as each row.
        $allCreated = $this->aggregations->aggregate($transactions, $created)['metrics'];
        $allPaid = $this->aggregations->aggregate($transactions, $paid)['metrics'];
        $successDivisor = $allCreated['success_purchases'];
        $revenueDivisor = round((float) $allPaid['revenue'], 2);

        $platforms = $this->dashboardTransactions->platformLabels($platformIds);
        $groups = $this->groupByProduct($transactions);
        $labels = $this->labels($transactions);
        $rows = [];

        foreach ($groups as $groupKey => $group) {
            [$platformId, $productKey] = explode(':', $groupKey, 2);
            $row = $this->row(
                $platforms[(int) $platformId],
                $productKey,
                $labels[$groupKey] ?? self::UNNAMED_PRODUCT,
                $this->aggregations->aggregate($group, $created)['metrics'],
                $this->aggregations->aggregate($group, $paid)['metrics'],
                $successDivisor,
                $revenueDivisor,
            );

            if ($row['total_transactions'] > 0 || $row['success_payments'] > 0) {
                $rows[] = $row;
            }
        }

        return [
            ...$base,
            'is_empty' => $rows === [],
            'message' => $rows === [] ? 'Tidak ada data produk untuk filter yang dipilih.' : null,
            'totals' => [
                'products' => count($rows),
                'total_transactions' => $allCreated['total_checkouts'],
                'success_purchases' => $successDivisor,
                'revenue' => $revenueDivisor,
            ],
            'contribution' => [
                'transaction' => $successDivisor > 0 ? self::CONTRIBUTION_AVAILABLE : self::CONTRIBUTION_ZERO_DIVISOR,
                'revenue' => $revenueDivisor > 0 ? self::CONTRIBUTION_AVAILABLE : self::CONTRIBUTION_ZERO_DIVISOR,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{id: int, slug: string, name: string}  $platform
     * @param  array<string, mixed>  $created
     * @param  array<string, mixed>  $paid
     * @return array<string, mixed>
     */
    private function row(array $platform, string $productKey, string $name, array $created, array $paid, int $successDivisor, float $revenueDivisor): array
    {
        $breakdown = $created['status_breakdown'];
        $revenue = round((float) $paid['revenue'], 2);

        return [
            'key' => $platform['slug'].':'.$productKey,
            'product' => $name,
            'is_unnamed' => $productKey === '',
            'platform' => $platform,
            'total_transactions' => $created['total_checkouts'],
            'success_purchases' => $created['success_purchases'],
            'pending_purchases' => $breakdown['pending']['count'],
            'unsuccessful_purchases' => $created['unsuccessful_purchases'],
            'revenue' => $revenue,
            'transaction_contribution' => Ratio::percentage($created['success_purchases'], $successDivisor),
            'revenue_contribution' => Ratio::percentage($revenue, $revenueDivisor),
            'total_breakdown' => [
                'purchase_attempts' => $created['purchase_attempts'],
                'claim_checkouts' => $created['claim_checkouts'],
            ],
            'unsuccessful_breakdown' => [
                'expired' => $breakdown['expired']['count'],
                'canceled' => $breakdown['canceled']['count'],
                'failed' => $breakdown['failed']['count'],
            ],
            'unknown_status_count' => $created['unknown_status_count'],
            'success_payments' => $paid['success_payments'],
        ];
    }

    /**
     * Products with the same name on different platforms stay separate rows.
     *
     * @param  list<array<string, mixed>>  $transactions
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByProduct(array $transactions): array
    {
        $groups = [];

        foreach ($transactions as $transaction) {
            $groups[$this->groupKey($transaction)][] = $transaction;
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function groupKey(array $transaction): string
    {
        return (int) $transaction['platform_id'].':'.self::productKey($transaction['product'] ?? null);
    }

    /**
     * The label of each product: its most used spelling, then the first in sort order.
     *
     * @param  list<array<string, mixed>>  $transactions
     * @return array<string, string>
     */
    private function labels(array $transactions): array
    {
        $spellings = [];

        foreach ($transactions as $transaction) {
            $name = self::displayName($transaction['product'] ?? null);

            if ($name !== '') {
                $key = $this->groupKey($transaction);
                $spellings[$key][$name] = ($spellings[$key][$name] ?? 0) + 1;
            }
        }

        return array_map(function (array $counts) {
            uksort($counts, fn (string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

            return (string) array_key_first($counts);
        }, $spellings);
    }

    /**
     * Case-insensitive match on the product name; an empty search keeps every row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function searched(array $rows, string $search): array
    {
        $needle = self::productKey($search);

        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row) => str_contains(mb_strtolower($row['product']), $needle)));
    }

    /**
     * Orders by the chosen column, then by product and platform name so equal values keep a stable order.
     * An unknown contribution sorts below 0.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows, string $sort, string $direction): array
    {
        $sign = $direction === 'asc' ? 1 : -1;
        $value = fn (array $row) => $sort === 'product' ? mb_strtolower($row['product']) : ($row[$sort] ?? -1);

        usort($rows, fn (array $a, array $b) => ($sign * ($value($a) <=> $value($b)))
            ?: [mb_strtolower($a['product']), $a['platform']['name']] <=> [mb_strtolower($b['product']), $b['platform']['name']]);

        return $rows;
    }
}
