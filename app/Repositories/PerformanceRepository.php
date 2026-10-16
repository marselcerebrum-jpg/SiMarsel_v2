<?php

namespace App\Repositories;

use App\Models\Downloader;
use App\Models\Transaction;
use App\Repositories\Concerns\AppliesPerformanceFilter;
use App\Support\PerformanceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PerformanceRepository
{
    use AppliesPerformanceFilter;

    /**
     * Emails are compared case-insensitively and without surrounding spaces.
     */
    private const NORMALIZED_EMAIL = 'LOWER(TRIM(customer_email))';

    /**
     * Counts first purchases (Perdana) as new_users and renewals (Perpanjangan) as repeat_orders.
     * Bindings: Transaction::TYPE_FIRST, Transaction::TYPE_RENEWAL.
     */
    private const PRODUCT_TYPE_COUNTS = 'COALESCE(SUM(CASE WHEN product_type = ? THEN 1 ELSE 0 END), 0) as new_users, '
        .'COALESCE(SUM(CASE WHEN product_type = ? THEN 1 ELSE 0 END), 0) as repeat_orders';

    /**
     * Revenue, transaction count, and the number of first purchases and renewals in one query.
     *
     * @return array{revenue: float, transactions: int, new_users: int, repeat_orders: int}
     */
    public function transactionTotals(PerformanceFilter $filter): array
    {
        $totals = $this->successfulTransactions($filter)
            ->toBase()
            ->selectRaw(
                'COALESCE(SUM(nominal), 0) as revenue, COUNT(*) as transactions, '.self::PRODUCT_TYPE_COUNTS,
                [Transaction::TYPE_FIRST, Transaction::TYPE_RENEWAL],
            )
            ->first();

        return [
            'revenue' => (float) $totals->revenue,
            'transactions' => (int) $totals->transactions,
            'new_users' => (int) $totals->new_users,
            'repeat_orders' => (int) $totals->repeat_orders,
        ];
    }

    public function countUniqueCustomers(PerformanceFilter $filter): int
    {
        return (int) $this->successfulTransactions($filter)
            ->withCustomerEmail()
            ->toBase()
            ->selectRaw('COUNT(DISTINCT '.self::NORMALIZED_EMAIL.') as total')
            ->value('total');
    }

    public function totalDownloaders(PerformanceFilter $filter): int
    {
        return (int) $this->downloaders($filter)->sum('total');
    }

    /**
     * Revenue, transaction count, first purchases, and renewals per day.
     *
     * @return Collection<int, object{day: string, revenue: string|float, transactions: int, new_users: int, repeat_orders: int}>
     */
    public function dailyTransactionTotals(PerformanceFilter $filter): Collection
    {
        return $this->successfulTransactions($filter)
            ->toBase()
            ->selectRaw(
                'DATE(paid_date) as day, SUM(nominal) as revenue, COUNT(*) as transactions, '.self::PRODUCT_TYPE_COUNTS,
                [Transaction::TYPE_FIRST, Transaction::TYPE_RENEWAL],
            )
            ->groupByRaw('DATE(paid_date)')
            ->get();
    }

    /**
     * Downloader total per day.
     *
     * @return Collection<int, object{day: string, total: int}>
     */
    public function dailyDownloaderTotals(PerformanceFilter $filter): Collection
    {
        return $this->downloaders($filter)
            ->toBase()
            ->selectRaw('DATE(date) as day, SUM(total) as total')
            ->groupByRaw('DATE(date)')
            ->get();
    }

    /**
     * Successful transactions in the filter's periods and platforms.
     *
     * @return Builder<Transaction>
     */
    private function successfulTransactions(PerformanceFilter $filter): Builder
    {
        return $this->applyPerformanceFilter(Transaction::query()->successful(), $filter, 'paid_date', 'platform_id');
    }

    /**
     * @return Builder<Downloader>
     */
    private function downloaders(PerformanceFilter $filter): Builder
    {
        return $this->applyPerformanceFilter(Downloader::query(), $filter, 'date', 'platform_id');
    }
}
