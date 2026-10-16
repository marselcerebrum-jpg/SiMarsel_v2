<?php

namespace App\Repositories;

use App\Models\Transaction;
use App\Repositories\Concerns\AppliesPerformanceFilter;
use App\Support\PerformanceFilter;
use Illuminate\Support\Collection;

class PaymentMethodDistributionRepository
{
    use AppliesPerformanceFilter;

    /**
     * Transactions and revenue per payment method from successful transactions.
     * Methods are grouped case- and whitespace-insensitively; an empty method is grouped under an empty name.
     *
     * @return Collection<int, object{name: string|null, transactions: int, revenue: string|float}>
     */
    public function totalsPerPaymentMethod(PerformanceFilter $filter): Collection
    {
        return $this->applyPerformanceFilter(Transaction::query()->successful(), $filter, 'paid_date', 'platform_id')
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
