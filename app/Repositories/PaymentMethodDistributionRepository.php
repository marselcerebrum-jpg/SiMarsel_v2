<?php

namespace App\Repositories;

use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PaymentMethodDistributionRepository
{
    /**
     * Transactions and revenue per payment method from successful transactions.
     * Methods are grouped case- and whitespace-insensitively; an empty method is grouped under an empty name.
     *
     * @return Collection<int, object{name: string|null, transactions: int, revenue: string|float}>
     */
    public function totalsPerPaymentMethod(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug): Collection
    {
        return Transaction::query()
            ->successful()
            ->whereBetween('paid_date', [
                $from->copy()->startOfDay()->toDateTimeString(),
                $to->copy()->endOfDay()->toDateTimeString(),
            ])
            ->when($platformSlug, fn (Builder $query) => $query->whereHas(
                'platform',
                fn (Builder $platform) => $platform->where('slug', $platformSlug),
            ))
            ->toBase()
            ->selectRaw(
                'MIN(TRIM(payment_method)) as name, '
                .'COUNT(*) as transactions, '
                .'COALESCE(SUM(nominal), 0) as revenue'
            )
            ->groupByRaw("LOWER(TRIM(COALESCE(payment_method, '')))")
            ->get();
    }
}
