<?php

namespace App\Repositories;

use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class PlatformContributionRepository
{
    /**
     * Revenue, transactions, and unique customer emails per platform.
     * Platforms without successful transactions in the period are still returned with zero values.
     *
     * @return Collection<int, object{slug: string, name: string, revenue: string|float, transactions: int, users: int}>
     */
    public function totalsPerPlatform(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug): Collection
    {
        return Platform::query()
            ->when($platformSlug, fn (Builder $query) => $query->where('platforms.slug', $platformSlug))
            ->leftJoin('transactions', function (JoinClause $join) use ($from, $to) {
                $join->on('transactions.platform_id', '=', 'platforms.id')
                    ->where('transactions.status_message', Transaction::STATUS_SUCCESS)
                    ->whereBetween('transactions.paid_date', [
                        $from->copy()->startOfDay()->toDateTimeString(),
                        $to->copy()->endOfDay()->toDateTimeString(),
                    ]);
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
