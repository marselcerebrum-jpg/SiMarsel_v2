<?php

namespace App\Repositories;

use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PackageDetailRepository
{
    /**
     * Revenue, transaction count, and lowest and highest price per package, keyed by package name.
     *
     * @return Collection<string, object{product: string|null, revenue: string|float, transactions: int, low_price: string|float, high_price: string|float}>
     */
    public function priceTotalsPerPackage(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug, ?string $search): Collection
    {
        return $this->transactions($from, $to, $platformSlug, $search)
            ->toBase()
            ->selectRaw('product, SUM(nominal) as revenue, COUNT(*) as transactions, MIN(nominal) as low_price, MAX(nominal) as high_price')
            ->groupBy('product')
            ->get()
            ->keyBy(fn (object $row) => (string) $row->product);
    }

    /**
     * Lowest and highest number of transactions in a single day, and the number of days with sales, per package.
     *
     * @return Collection<string, object{product: string|null, low_trx: int, high_trx: int, active_days: int}>
     */
    public function dailyTransactionRangePerPackage(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug, ?string $search): Collection
    {
        $daily = $this->transactions($from, $to, $platformSlug, $search)
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
    private function transactions(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug, ?string $search): Builder
    {
        return Transaction::query()
            ->successful()
            ->when($platformSlug, fn (Builder $query) => $query->whereIn(
                'platform_id',
                Platform::select('id')->where('slug', $platformSlug),
            ))
            ->when($search, fn (Builder $query) => $query->whereRaw(
                "LOWER(product) LIKE ? ESCAPE '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%'],
            ))
            ->whereBetween('paid_date', [
                $from->copy()->startOfDay()->toDateTimeString(),
                $to->copy()->endOfDay()->toDateTimeString(),
            ]);
    }
}
