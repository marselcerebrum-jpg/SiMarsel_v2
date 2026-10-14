<?php

namespace App\Repositories;

use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductPerformanceRepository
{
    /**
     * Revenue, transactions, first purchases, renewals, and unique customer emails per product.
     *
     * @return Collection<int, object{product: string|null, revenue: string|float, transactions: int, new_users: int, repeat_orders: int, users: int}>
     */
    public function totalsPerProduct(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug): Collection
    {
        return Transaction::query()
            ->successful()
            ->when($platformSlug, fn (Builder $query) => $query->whereIn(
                'platform_id',
                Platform::select('id')->where('slug', $platformSlug),
            ))
            ->whereBetween('paid_date', [
                $from->copy()->startOfDay()->toDateTimeString(),
                $to->copy()->endOfDay()->toDateTimeString(),
            ])
            ->toBase()
            ->selectRaw(
                'product, '
                .'SUM(nominal) as revenue, '
                .'COUNT(*) as transactions, '
                .'COALESCE(SUM(CASE WHEN product_type = ? THEN 1 ELSE 0 END), 0) as new_users, '
                .'COALESCE(SUM(CASE WHEN product_type = ? THEN 1 ELSE 0 END), 0) as repeat_orders, '
                ."COUNT(DISTINCT CASE WHEN customer_email IS NOT NULL AND customer_email != '' "
                .'THEN LOWER(TRIM(customer_email)) END) as users',
                [Transaction::TYPE_FIRST, Transaction::TYPE_RENEWAL],
            )
            ->groupBy('product')
            ->get();
    }
}
