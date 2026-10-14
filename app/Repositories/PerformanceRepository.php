<?php

namespace App\Repositories;

use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PerformanceRepository
{
    /**
     * Emails are compared case-insensitively and without surrounding spaces.
     */
    private const NORMALIZED_EMAIL = 'LOWER(TRIM(customer_email))';

    public function findPlatformIdBySlug(string $slug): ?int
    {
        return Platform::where('slug', $slug)->value('id');
    }

    /**
     * Revenue, transaction count, and the number of first purchases and renewals in one query.
     *
     * @return array{revenue: float, transactions: int, new_users: int, repeat_orders: int}
     */
    public function transactionTotals(CarbonInterface $from, CarbonInterface $to, ?int $platformId): array
    {
        $totals = $this->successfulTransactions($platformId)
            ->whereBetween('paid_date', $this->range($from, $to))
            ->toBase()
            ->selectRaw(
                'COALESCE(SUM(nominal), 0) as revenue, COUNT(*) as transactions, '
                .'COALESCE(SUM(CASE WHEN product_type = ? THEN 1 ELSE 0 END), 0) as new_users, '
                .'COALESCE(SUM(CASE WHEN product_type = ? THEN 1 ELSE 0 END), 0) as repeat_orders',
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

    public function countUniqueCustomers(CarbonInterface $from, CarbonInterface $to, ?int $platformId): int
    {
        return (int) $this->successfulTransactions($platformId)
            ->withCustomerEmail()
            ->whereBetween('paid_date', $this->range($from, $to))
            ->toBase()
            ->selectRaw('COUNT(DISTINCT '.self::NORMALIZED_EMAIL.') as total')
            ->value('total');
    }

    public function totalDownloaders(CarbonInterface $from, CarbonInterface $to, ?int $platformId): int
    {
        return (int) Downloader::query()
            ->when($platformId, fn (Builder $query) => $query->where('platform_id', $platformId))
            ->whereBetween('date', $this->range($from, $to))
            ->sum('total');
    }

    /**
     * Revenue and transaction count per day.
     *
     * @return Collection<int, object{day: string, revenue: string|float, transactions: int}>
     */
    public function dailyTransactionTotals(CarbonInterface $from, CarbonInterface $to, ?int $platformId): Collection
    {
        return $this->successfulTransactions($platformId)
            ->whereBetween('paid_date', $this->range($from, $to))
            ->toBase()
            ->selectRaw('DATE(paid_date) as day, SUM(nominal) as revenue, COUNT(*) as transactions')
            ->groupByRaw('DATE(paid_date)')
            ->get();
    }

    /**
     * Downloader total per day.
     *
     * @return Collection<int, object{day: string, total: int}>
     */
    public function dailyDownloaderTotals(CarbonInterface $from, CarbonInterface $to, ?int $platformId): Collection
    {
        return Downloader::query()
            ->when($platformId, fn (Builder $query) => $query->where('platform_id', $platformId))
            ->whereBetween('date', $this->range($from, $to))
            ->toBase()
            ->selectRaw('DATE(date) as day, SUM(total) as total')
            ->groupByRaw('DATE(date)')
            ->get();
    }

    /**
     * Number of successful transactions per customer email per day.
     *
     * @return Collection<int, object{day: string, email: string, transactions: int}>
     */
    public function dailyCustomerTransactions(CarbonInterface $from, CarbonInterface $to, ?int $platformId): Collection
    {
        return $this->successfulTransactions($platformId)
            ->withCustomerEmail()
            ->whereBetween('paid_date', $this->range($from, $to))
            ->toBase()
            ->selectRaw('DATE(paid_date) as day, '.self::NORMALIZED_EMAIL.' as email, COUNT(*) as transactions')
            ->groupByRaw('DATE(paid_date), '.self::NORMALIZED_EMAIL)
            ->get();
    }

    /**
     * First successful purchase day of every customer whose first purchase falls in the period.
     *
     * @return Collection<int, object{email: string, first_day: string}>
     */
    public function firstPurchaseDays(CarbonInterface $from, CarbonInterface $to, ?int $platformId): Collection
    {
        [$start, $end] = $this->range($from, $to);

        return $this->successfulTransactions($platformId)
            ->withCustomerEmail()
            ->where('paid_date', '<=', $end)
            ->toBase()
            ->selectRaw(self::NORMALIZED_EMAIL.' as email, DATE(MIN(paid_date)) as first_day')
            ->groupByRaw(self::NORMALIZED_EMAIL)
            ->havingRaw('MIN(paid_date) >= ?', [$start])
            ->get();
    }

    /**
     * @return Builder<Transaction>
     */
    private function successfulTransactions(?int $platformId): Builder
    {
        return Transaction::query()
            ->successful()
            ->when($platformId, fn (Builder $query) => $query->where('platform_id', $platformId));
    }

    /**
     * Full-day bounds so date columns compare correctly on every database driver.
     *
     * @return array{0: string, 1: string}
     */
    private function range(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            $from->copy()->startOfDay()->toDateTimeString(),
            $to->copy()->endOfDay()->toDateTimeString(),
        ];
    }
}
