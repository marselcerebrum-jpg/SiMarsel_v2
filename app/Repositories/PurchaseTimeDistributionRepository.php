<?php

namespace App\Repositories;

use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PurchaseTimeDistributionRepository
{
    /**
     * Successful transactions per purchase hour ("00" - "23"); transactions without a paid_time have a null hour.
     *
     * @return Collection<int, object{hour: string|null, transactions: int}>
     */
    public function transactionsPerHour(CarbonInterface $from, CarbonInterface $to, ?string $platformSlug): Collection
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
            ->selectRaw('SUBSTR(paid_time, 1, 2) as hour, COUNT(*) as transactions')
            ->groupByRaw('SUBSTR(paid_time, 1, 2)')
            ->get();
    }
}
