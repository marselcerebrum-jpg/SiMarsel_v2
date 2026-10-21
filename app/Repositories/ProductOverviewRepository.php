<?php

namespace App\Repositories;

use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductOverviewRepository
{
    /**
     * Every platform in the master table, ordered by name.
     *
     * @return Collection<int, object{slug: string, name: string}>
     */
    public function platforms(): Collection
    {
        return Platform::orderBy('name')->toBase()->get(['slug', 'name']);
    }

    /**
     * Distinct dates that have successful transactions, optionally for one platform.
     *
     * @return Collection<int, CarbonInterface>
     */
    public function transactionDates(?string $platformSlug): Collection
    {
        return Transaction::query()
            ->successful()
            ->when($platformSlug, fn (Builder $query) => $this->wherePlatform($query, $platformSlug))
            ->distinct()
            ->pluck('paid_date');
    }

    /**
     * Revenue, transaction count, and lowest and highest price per product, keyed by product name.
     *
     * @return Collection<string, object{product: string|null, revenue: string|float, transactions: int, low_price: string|float, high_price: string|float}>
     */
    public function totalsPerProduct(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug): Collection
    {
        return $this->transactions($from, $to, $platformSlug)
            ->toBase()
            ->selectRaw('product, SUM(nominal) as revenue, COUNT(*) as transactions, MIN(nominal) as low_price, MAX(nominal) as high_price')
            ->groupBy('product')
            ->get()
            ->keyBy(fn (object $row) => (string) $row->product);
    }

    /**
     * Lowest and highest number of transactions in a single day, and the number of days with sales, per product.
     *
     * @return Collection<string, object{product: string|null, low_trx: int, high_trx: int, active_days: int}>
     */
    public function dailyTransactionRangePerProduct(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug): Collection
    {
        $daily = $this->transactions($from, $to, $platformSlug)
            ->toBase()
            ->selectRaw('product, paid_date, COUNT(*) as total')
            ->groupBy('product', 'paid_date');

        return DB::query()
            ->fromSub($daily, 'daily')
            ->selectRaw('product, MIN(total) as low_trx, MAX(total) as high_trx, COUNT(*) as active_days')
            ->groupBy('product')
            ->get()
            ->keyBy(fn (object $row) => (string) $row->product);
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactions(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug): Builder
    {
        return Transaction::query()
            ->successful()
            ->when($platformSlug, fn (Builder $query) => $this->wherePlatform($query, $platformSlug))
            ->whereBetween('paid_date', [
                $from->copy()->startOfDay()->toDateTimeString(),
                $to->copy()->endOfDay()->toDateTimeString(),
            ]);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private function wherePlatform(Builder $query, string $platformSlug): Builder
    {
        return $query->whereIn('platform_id', Platform::select('id')->where('slug', $platformSlug));
    }
}
