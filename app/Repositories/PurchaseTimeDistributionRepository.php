<?php

namespace App\Repositories;

use App\Models\Transaction;
use App\Repositories\Concerns\AppliesPerformanceFilter;
use App\Support\PerformanceFilter;
use Illuminate\Support\Collection;

class PurchaseTimeDistributionRepository
{
    use AppliesPerformanceFilter;

    /**
     * Successful transactions per purchase hour ("00" - "23"); transactions without a paid_time have a null hour.
     *
     * @return Collection<int, object{hour: string|null, transactions: int}>
     */
    public function transactionsPerHour(PerformanceFilter $filter): Collection
    {
        return $this->applyPerformanceFilter(Transaction::query()->successful(), $filter, 'paid_date', 'platform_id')
            ->toBase()
            ->selectRaw('SUBSTR(paid_time, 1, 2) as hour, COUNT(*) as transactions')
            ->groupByRaw('SUBSTR(paid_time, 1, 2)')
            ->get();
    }
}
