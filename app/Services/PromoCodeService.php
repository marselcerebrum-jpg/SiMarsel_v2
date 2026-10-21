<?php

namespace App\Services;

use App\Exceptions\PromoCodeInUseException;
use App\Models\PromoCode;
use App\Models\PromoCodeVersion;
use App\Models\TransactionPromoCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
     * Promo codes with their platform, funnel, source, and number of transaction usages, newest first.
     * The search matches the code and the platform, funnel, and source names.
     *
     * @return LengthAwarePaginator<int, PromoCode>
     */
    public function list(?string $search, ?int $platformId, int $perPage): LengthAwarePaginator
    {
        $search = trim((string) $search);

        return PromoCode::query()
            ->with(['platform', 'funnel', 'source'])
            ->withCount('usages')
            ->when($platformId !== null, fn (Builder $query) => $query->where('platform_id', $platformId))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn (Builder $query) => $query
                    ->where('code', 'like', '%'.PromoCode::normalizeCode($search).'%')
                    ->orWhereHas('platform', fn (Builder $platform) => $platform->where('name', 'like', $like)->orWhere('slug', 'like', $like))
                    ->orWhereHas('funnel', fn (Builder $funnel) => $funnel->where('name', 'like', $like))
                    ->orWhereHas('source', fn (Builder $source) => $source->where('name', 'like', $like)));
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Register a code from Single Entry or the unknown-code alert, then link the earlier usages
     * of the same platform and code that were not mapped yet.
     */
    public function register(int $platformId, string $code, ?int $funnelId = null, ?int $sourceId = null): PromoCode
    {
        return DB::transaction(function () use ($platformId, $code, $funnelId, $sourceId) {
            $promoCode = $this->create($platformId, $code, $funnelId, $sourceId);
            $this->linkUnmappedUsages($promoCode);

            return $promoCode;
        });
    }

    public function registerFromAlert(int $platformId, string $code, ?int $funnelId = null, ?int $sourceId = null): PromoCode
    {
        return $this->register($platformId, $code, $funnelId, $sourceId);
    }

    /**
     * Change the funnel and source. The current version ends now and a new one starts now,
     * so usages classified before keep their snapshot.
     */
    public function updateMetadata(PromoCode $promoCode, ?int $funnelId, ?int $sourceId): PromoCode
    {
        return $this->update($promoCode, ['funnel_id' => $funnelId, 'source_id' => $sourceId]);
    }

    /**
     * Update the fields that are given. Funnel and source can always change and start a new version.
     * The code and platform identify the transactions that used the code, so they can only change
     * while no transaction has used it yet.
     *
     * @param  array{platform_id?: int, code?: string, funnel_id?: int|null, source_id?: int|null}  $data
     *
     * @throws PromoCodeInUseException
     */
    public function update(PromoCode $promoCode, array $data): PromoCode
    {
        $platformId = (int) ($data['platform_id'] ?? $promoCode->platform_id);
        $code = PromoCode::normalizeCode($data['code'] ?? $promoCode->code);
        $funnelId = array_key_exists('funnel_id', $data) ? $data['funnel_id'] : $promoCode->funnel_id;
        $sourceId = array_key_exists('source_id', $data) ? $data['source_id'] : $promoCode->source_id;

        $identityChanged = $platformId !== (int) $promoCode->platform_id || $code !== $promoCode->code;

        if ($identityChanged && ($usages = $promoCode->usages()->count()) > 0) {
            throw PromoCodeInUseException::identityChange($usages);
        }

        if (! $identityChanged && $this->sameId($promoCode->funnel_id, $funnelId) && $this->sameId($promoCode->source_id, $sourceId)) {
            return $promoCode;
        }

        return DB::transaction(function () use ($promoCode, $platformId, $code, $funnelId, $sourceId, $identityChanged) {
            $now = $this->now();
            $current = $promoCode->currentVersion()->lockForUpdate()->first();

            $promoCode->update([
                'platform_id' => $platformId,
                'code' => $code,
                'funnel_id' => $funnelId,
                'source_id' => $sourceId,
            ]);

            // A second edit within the same second replaces that version instead of starting another one.
            if ($current !== null && $current->effective_from->equalTo($now)) {
                $current->update($this->versionMetadata($promoCode));
            } else {
                $current?->update(['effective_until' => $now]);
                $this->openVersion($promoCode, $now);
            }

            if ($identityChanged) {
                $this->linkUnmappedUsages($promoCode);
            }

            return $promoCode;
        });
    }

    /**
     * Delete a promo code that no transaction has used. Its versions only describe this code,
     * so they are removed together on purpose; usages can never be removed this way.
     *
     * @throws PromoCodeInUseException
     */
    public function delete(PromoCode $promoCode): void
    {
        DB::transaction(function () use ($promoCode) {
            $usages = $promoCode->usages()->lockForUpdate()->count();

            if ($usages > 0) {
                throw PromoCodeInUseException::delete($usages);
            }

            $promoCode->versions()->delete();
            $promoCode->delete();
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
            'effective_from' => $from,
        ]);
    }

    /**
     * @return array{platform_id: int, code: string, funnel_id: int|null, funnel_name_snapshot: string|null, source_id: int|null, source_name_snapshot: string|null}
     */
    private function versionMetadata(PromoCode $promoCode): array
    {
        $promoCode->load(['funnel', 'source']);

        return [
            'platform_id' => $promoCode->platform_id,
            'code' => $promoCode->code,
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
