<?php

namespace App\Repositories;

use App\Models\Platform;
use App\Support\Period\PeriodCoverageSource;
use App\Support\TransactionAggregation\TransactionRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads dashboard_snapshots.cutoff_at from the complete records of the requested scope.
 * Until the table is migrated (FIX-15) every platform has unknown coverage.
 */
class DashboardSnapshotCoverageRepository implements PeriodCoverageSource
{
    private const TABLE = 'dashboard_snapshots';

    public function completeCutoffs(array $platforms, string $dataset, CarbonImmutable $dateFrom, CarbonImmutable $dateTo): array
    {
        $slugs = Platform::query()
            ->when($platforms !== [], fn ($query) => $query->whereIn('slug', $platforms))
            ->pluck('slug', 'id');

        $cutoffs = $slugs->mapWithKeys(fn (string $slug) => [$slug => []])->all();

        if ($slugs->isEmpty() || ! Schema::hasTable(self::TABLE)) {
            return $cutoffs;
        }

        $records = DB::table(self::TABLE)
            ->whereIn('platform_id', $slugs->keys())
            ->where('dataset', $dataset)
            ->whereDate('date_from', $dateFrom->toDateString())
            ->whereDate('date_to', $dateTo->toDateString())
            ->where('is_complete', true)
            ->get(['platform_id', 'cutoff_at']);

        foreach ($records as $record) {
            $cutoffs[$slugs[$record->platform_id]][] = CarbonImmutable::parse($record->cutoff_at, TransactionRecord::TIMEZONE)
                ->setTimezone(TransactionRecord::TIMEZONE);
        }

        return $cutoffs;
    }
}
