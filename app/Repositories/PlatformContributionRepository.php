<?php

namespace App\Repositories;

use App\Models\Platform;
use App\Models\Transaction;
use App\Repositories\Concerns\AppliesPerformanceFilter;
use App\Support\PerformanceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class PlatformContributionRepository
{
    use AppliesPerformanceFilter;

    /**
     * Revenue, transactions, and unique customer emails per platform.
     * Selected platforms (or every platform) are returned even without successful transactions in the period.
     *
     * @return Collection<int, object{slug: string, name: string, revenue: string|float, transactions: int, users: int}>
     */
    public function totalsPerPlatform(PerformanceFilter $filter): Collection
    {
        return Platform::query()
            ->when($filter->platforms !== [], fn (Builder $query) => $query->whereIn('platforms.slug', $filter->platforms))
            ->leftJoin('transactions', function (JoinClause $join) use ($filter) {
                $join->on('transactions.platform_id', '=', 'platforms.id')
                    ->where('transactions.status_message', Transaction::STATUS_SUCCESS);

                $this->whereInPerformancePeriod($join, $filter, 'transactions.paid_date');
            })
            ->toBase()
            ->selectRaw(
                'platforms.slug, platforms.name, '
                .'COALESCE(SUM(transactions.nominal), 0) as revenue, '
                .'COUNT(transactions.id) as transactions, '
                ."COUNT(DISTINCT CASE WHEN transactions.customer_email IS NOT NULL AND transactions.customer_email != '' "
                .'THEN LOWER(TRIM(transactions.customer_email)) END) as users'
            )
            ->groupBy('platforms.id', 'platforms.slug', 'platforms.name')
            ->get();
    }
}
