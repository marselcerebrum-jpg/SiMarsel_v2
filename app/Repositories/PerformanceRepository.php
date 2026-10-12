<?php

namespace App\Repositories;

use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
     * @return array{revenue: float, transactions: int}
     */
    public function transactionTotals(CarbonInterface $from, CarbonInterface $to, ?int $platformId): array
    {
        $totals = $this->successfulTransactions($platformId)
            ->whereBetween('paid_date', $this->range($from, $to))
            ->toBase()
            ->selectRaw('COALESCE(SUM(nominal), 0) as revenue, COUNT(*) as transactions')
            ->first();

        return [
            'revenue' => (float) $totals->revenue,
            'transactions' => (int) $totals->transactions,
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

    /**
     * Customers with more than one successful transaction in the period.
     */
    public function countRepeatCustomers(CarbonInterface $from, CarbonInterface $to, ?int $platformId): int
    {
        $customers = $this->successfulTransactions($platformId)
            ->withCustomerEmail()
            ->whereBetween('paid_date', $this->range($from, $to))
            ->selectRaw(self::NORMALIZED_EMAIL.' as email')
            ->groupByRaw(self::NORMALIZED_EMAIL)
            ->havingRaw('COUNT(*) > 1');

        return DB::query()->fromSub($customers, 'repeat_customers')->count();
    }

    /**
     * Customers whose first successful transaction ever falls in the period.
     */
    public function countNewCustomers(CarbonInterface $from, CarbonInterface $to, ?int $platformId): int
    {
        [$start, $end] = $this->range($from, $to);

        $customers = $this->successfulTransactions($platformId)
            ->withCustomerEmail()
            ->where('paid_date', '<=', $end)
            ->selectRaw(self::NORMALIZED_EMAIL.' as email')
            ->groupByRaw(self::NORMALIZED_EMAIL)
            ->havingRaw('MIN(paid_date) >= ?', [$start]);

        return DB::query()->fromSub($customers, 'new_customers')->count();
    }

    public function totalDownloaders(CarbonInterface $from, CarbonInterface $to, ?int $platformId): int
    {
        return (int) Downloader::query()
            ->when($platformId, fn (Builder $query) => $query->where('platform_id', $platformId))
            ->whereBetween('date', $this->range($from, $to))
            ->sum('total');
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
