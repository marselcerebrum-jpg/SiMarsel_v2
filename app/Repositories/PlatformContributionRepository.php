<?php

namespace App\Repositories;

use App\Enums\MarkazTransactionStatus;
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
     * The selected platforms by slug, or every platform when no slug is given.
     *
     * @param  list<string>  $slugs
     * @return Collection<int, object{id: int, slug: string, name: string}>
     */
    public function platformsBySlugs(array $slugs): Collection
    {
        return Platform::query()
            ->when($slugs !== [], fn (Builder $query) => $query->whereIn('platforms.slug', $slugs))
            ->orderBy('platforms.id')
            ->toBase()
            ->get(['platforms.id', 'platforms.slug', 'platforms.name']);
    }

    /**
     * Revenue, transactions, and unique paying accounts per platform.
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
                    ->whereRaw(Transaction::REPORT_STATUS_EXPRESSION.' = ?', [MarkazTransactionStatus::Success->value]);

                $this->whereInPerformancePeriod($join, $filter, 'transactions.paid_date');
            })
            ->toBase()
            ->selectRaw(
                'platforms.slug, platforms.name, '
                .'COALESCE(SUM(transactions.nominal), 0) as revenue, '
                .'COUNT(transactions.id) as transactions, '
                .'COUNT(DISTINCT transactions.markaz_customer_id) as users'
            )
            ->groupBy('platforms.id', 'platforms.slug', 'platforms.name')
            ->get();
    }
}
