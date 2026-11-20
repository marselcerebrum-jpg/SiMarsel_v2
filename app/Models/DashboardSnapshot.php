<?php

namespace App\Models;

use App\Enums\DashboardDataset;
use Carbon\CarbonImmutable;
use Database\Factories\DashboardSnapshotFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Values of one platform and dataset recorded at cutoff_at (events before that moment, WIB).
 * The payload is a copy taken when recorded, so later changes to the stored transactions or
 * downloaders do not change it. Only is_complete = true snapshots may serve as a comparison.
 */
#[Fillable([
    'platform_id',
    'dataset',
    'date_from',
    'date_to',
    'cutoff_at',
    'is_complete',
    'payload',
    'recorded_at',
])]
class DashboardSnapshot extends Model
{
    /** @use HasFactory<DashboardSnapshotFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dataset' => DashboardDataset::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'cutoff_at' => 'immutable_datetime',
            'is_complete' => 'boolean',
            'payload' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DashboardSnapshot $snapshot) {
            $snapshot->recorded_at ??= CarbonImmutable::now();
        });
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * @param  Builder<DashboardSnapshot>  $query
     */
    public function scopeForPeriod(Builder $query, DashboardDataset $dataset, DateTimeInterface|string $dateFrom, DateTimeInterface|string $dateTo): void
    {
        $query->where('dataset', $dataset->value)
            ->whereDate('date_from', self::dateString($dateFrom))
            ->whereDate('date_to', self::dateString($dateTo));
    }

    /**
     * @param  Builder<DashboardSnapshot>  $query
     */
    public function scopeComplete(Builder $query): void
    {
        $query->where('is_complete', true);
    }

    /**
     * The latest cutoff_at at which every selected platform has a complete snapshot for the period.
     * Incomplete snapshots never count; null when no common complete cutoff is stored yet.
     *
     * @param  list<int>  $platformIds
     */
    public static function latestCompleteCutoff(
        array $platformIds,
        DashboardDataset $dataset,
        DateTimeInterface|string $dateFrom,
        DateTimeInterface|string $dateTo,
    ): ?CarbonImmutable {
        $platformIds = array_values(array_unique($platformIds));

        if ($platformIds === []) {
            return null;
        }

        $cutoff = static::query()
            ->forPeriod($dataset, $dateFrom, $dateTo)
            ->complete()
            ->whereIn('platform_id', $platformIds)
            ->groupBy('cutoff_at')
            ->havingRaw('COUNT(DISTINCT platform_id) = ?', [count($platformIds)])
            ->max('cutoff_at');

        return $cutoff === null ? null : CarbonImmutable::parse($cutoff);
    }

    /**
     * The row shape read by App\Services\ComparisonSnapshotService (WIB, ISO 8601).
     *
     * @return array<string, mixed>
     */
    public function toComparisonRow(): array
    {
        return [
            'platform_id' => $this->platform_id,
            'dataset' => $this->dataset->value,
            'date_from' => $this->date_from->toDateString(),
            'date_to' => $this->date_to->toDateString(),
            'cutoff_at' => $this->cutoff_at->toIso8601String(),
            'is_complete' => $this->is_complete,
            'payload' => $this->payload,
            'recorded_at' => $this->recorded_at->toIso8601String(),
        ];
    }

    private static function dateString(DateTimeInterface|string $date): string
    {
        return $date instanceof DateTimeInterface ? $date->format('Y-m-d') : CarbonImmutable::parse($date)->toDateString();
    }
}
