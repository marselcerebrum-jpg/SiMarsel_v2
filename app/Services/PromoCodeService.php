<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\PromoCodeVersion;
use App\Models\TransactionPromoCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Promo code master with versioned funnel/source metadata.
 * Every create or edit opens a new version from the moment it is saved; users never pick an effective date.
 */
class PromoCodeService
{
    /**
     * Snapshot columns of a usage that has no classification.
     */
    public const EMPTY_SNAPSHOT = [
        'funnel_id_snapshot' => null,
        'funnel_name_snapshot' => null,
        'source_id_snapshot' => null,
        'source_name_snapshot' => null,
    ];

    public function create(int $platformId, string $code, ?int $funnelId = null, ?int $sourceId = null): PromoCode
    {
        return DB::transaction(function () use ($platformId, $code, $funnelId, $sourceId) {
            $promoCode = PromoCode::create([
                'platform_id' => $platformId,
                'code' => $code,
                'funnel_id' => $funnelId,
                'source_id' => $sourceId,
            ]);

            $this->openVersion($promoCode, $this->now());

            return $promoCode;
        });
    }

    /**
     * Register a code found by the unknown-code alert, then link the earlier usages that were not mapped yet.
     */
    public function registerFromAlert(int $platformId, string $code, ?int $funnelId = null, ?int $sourceId = null): PromoCode
    {
        return DB::transaction(function () use ($platformId, $code, $funnelId, $sourceId) {
            $promoCode = $this->create($platformId, $code, $funnelId, $sourceId);
            $this->linkUnmappedUsages($promoCode);

            return $promoCode;
        });
    }

    /**
     * Change the funnel and source. The current version ends now and a new one starts now,
     * so usages classified before keep their snapshot.
     */
    public function updateMetadata(PromoCode $promoCode, ?int $funnelId, ?int $sourceId): PromoCode
    {
        if ($this->sameId($promoCode->funnel_id, $funnelId) && $this->sameId($promoCode->source_id, $sourceId)) {
            return $promoCode;
        }

        return DB::transaction(function () use ($promoCode, $funnelId, $sourceId) {
            $now = $this->now();
            $current = $promoCode->currentVersion()->lockForUpdate()->first();

            $promoCode->update(['funnel_id' => $funnelId, 'source_id' => $sourceId]);

            // A second edit within the same second replaces that version instead of starting another one.
            if ($current !== null && $current->effective_from->equalTo($now)) {
                $current->update($this->versionMetadata($promoCode));
            } else {
                $current?->update(['effective_until' => $now]);
                $this->openVersion($promoCode, $now);
            }

            return $promoCode;
        });
    }

    /**
     * Link usages of the same platform and code that have no master and no classification yet,
     * and fill them with the code's current metadata. Classified usages are never overwritten.
     *
     * @return int The number of usages linked.
     */
    public function linkUnmappedUsages(PromoCode $promoCode): int
    {
        $version = $promoCode->currentVersion()->first();

        return TransactionPromoCode::query()
            ->unmapped()
            ->unclassified()
            ->where('platform_id_snapshot', $promoCode->platform_id)
            ->where('code_snapshot', $promoCode->code)
            ->update(['promo_code_id' => $promoCode->id, ...$this->snapshotOf($version)]);
    }

    /**
     * The funnel/source snapshot of the version that was valid at the given moment.
     * Without such a version the snapshot stays empty: nothing is guessed.
     *
     * @return array{funnel_id_snapshot: int|null, funnel_name_snapshot: string|null, source_id_snapshot: int|null, source_name_snapshot: string|null}
     */
    public function snapshotAt(PromoCode $promoCode, CarbonInterface $moment): array
    {
        return $this->snapshotOf($promoCode->versions()->effectiveAt($moment)->first());
    }

    /**
     * @return array{funnel_id_snapshot: int|null, funnel_name_snapshot: string|null, source_id_snapshot: int|null, source_name_snapshot: string|null}
     */
    private function snapshotOf(?PromoCodeVersion $version): array
    {
        if ($version === null) {
            return self::EMPTY_SNAPSHOT;
        }

        return [
            'funnel_id_snapshot' => $version->funnel_id,
            'funnel_name_snapshot' => $version->funnel_name_snapshot,
            'source_id_snapshot' => $version->source_id,
            'source_name_snapshot' => $version->source_name_snapshot,
        ];
    }

    private function openVersion(PromoCode $promoCode, CarbonImmutable $from): PromoCodeVersion
    {
        return $promoCode->versions()->create([
            ...$this->versionMetadata($promoCode),
            'platform_id' => $promoCode->platform_id,
            'code' => $promoCode->code,
            'effective_from' => $from,
        ]);
    }

    /**
     * @return array{funnel_id: int|null, funnel_name_snapshot: string|null, source_id: int|null, source_name_snapshot: string|null}
     */
    private function versionMetadata(PromoCode $promoCode): array
    {
        $promoCode->load(['funnel', 'source']);

        return [
            'funnel_id' => $promoCode->funnel_id,
            'funnel_name_snapshot' => $promoCode->funnel?->name,
            'source_id' => $promoCode->source_id,
            'source_name_snapshot' => $promoCode->source?->name,
        ];
    }

    /**
     * Timestamps are stored without fractions of a second.
     */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfSecond();
    }

    private function sameId(int|string|null $current, ?int $new): bool
    {
        return ($current === null ? null : (int) $current) === $new;
    }
}
