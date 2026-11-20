<?php

namespace App\Repositories;

use App\Enums\DashboardDataset;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads stored transactions and dashboard_snapshots in the shape used by the shared aggregations
 * (App\Services\TransactionAggregationService and ComparisonSnapshotService). It only reads stored state.
 */
class DashboardTransactionRepository
{
    private const CONTRACT_COLUMNS = [
        'platform_id',
        'markaz_transaction_id',
        'markaz_ref_id',
        'markaz_customer_id',
        'markaz_created_at',
        'paid_date',
        'paid_time',
        'source_status',
        'effective_status',
        'is_claim_coupon',
        'nominal',
        'net_nominal',
    ];

    /**
     * The ids of the selected platform slugs; no slug means every platform.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    public function platformIds(array $slugs): array
    {
        return Platform::query()
            ->when($slugs !== [], fn (Builder $query) => $query->whereIn('slug', $slugs))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Transactions created or paid within [startAt, endAt), as contract rows. Rows without a Markaz
     * identity or creation time are older imports that the transaction dates contract cannot read.
     *
     * @param  list<int>  $platformIds
     * @return list<array<string, mixed>>
     */
    public function contractRows(array $platformIds, CarbonImmutable $startAt, CarbonImmutable $endAt): array
    {
        $lastDay = $endAt->eq($endAt->startOfDay()) ? $endAt->subDay() : $endAt;

        return Transaction::query()
            ->whereIn('platform_id', $platformIds)
            ->whereNotNull('markaz_transaction_id')
            ->whereNotNull('markaz_created_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $created) => $created
                    ->where('markaz_created_at', '>=', $startAt->format('Y-m-d H:i:s'))
                    ->where('markaz_created_at', '<', $endAt->format('Y-m-d H:i:s')))
                ->orWhere(fn (Builder $paid) => $paid
                    ->where('paid_date', '>=', $startAt->toDateString())
                    ->where('paid_date', '<', $lastDay->addDay()->toDateString())))
            ->toBase()
            ->get(self::CONTRACT_COLUMNS)
            ->map(fn (object $row) => [
                ...(array) $row,
                'paid_date' => $row->paid_date === null ? null : substr((string) $row->paid_date, 0, 10),
            ])
            ->all();
    }

    /**
     * The transactions snapshots recorded for exactly one day (date_from = date_to = $day), in the row
     * shape read by ComparisonSnapshotService::read().
     *
     * @param  list<int>  $platformIds
     * @return list<array<string, mixed>>
     */
    public function daySnapshots(array $platformIds, CarbonImmutable $day): array
    {
        return DashboardSnapshot::query()
            ->forPeriod(DashboardDataset::Transactions, $day, $day)
            ->whereIn('platform_id', $platformIds)
            ->get()
            ->map(fn (DashboardSnapshot $snapshot) => $snapshot->toComparisonRow())
            ->all();
    }
}
