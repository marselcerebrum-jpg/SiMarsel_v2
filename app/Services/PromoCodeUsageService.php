<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
use App\Support\PromoCodePayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns the raw Markaz promo_code value of a transaction into transaction_promo_codes rows.
 * The raw value on the transaction is never changed.
 */
class PromoCodeUsageService
{
    public const ANOMALY_LOG = 'promo_code_anomalies';

    public function __construct(private PromoCodeService $promoCodeService) {}

    /**
     * Upsert one usage per code position, so processing a transaction again never creates duplicates.
     *
     * @return bool False when attribution is suspended because the latest payload is anomalous.
     */
    public function normalize(Transaction $transaction): bool
    {
        return DB::transaction(function () use ($transaction) {
            // Serialize normalization of the same transaction and use its latest persisted payload.
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $result = $this->normalizeLocked($locked);
            $transaction->promo_code_anomaly = $locked->promo_code_anomaly;
            $transaction->syncOriginalAttribute('promo_code_anomaly');

            return $result;
        });
    }

    private function normalizeLocked(Transaction $transaction): bool
    {
        $codes = PromoCodePayload::codesByPosition($transaction->promo_code);
        $anomaly = PromoCodePayload::anomalyReason($codes);

        // Internal processing state must not change Markaz/source timestamps or trigger the observer.
        DB::table('transactions')->where('id', $transaction->id)->update(['promo_code_anomaly' => $anomaly !== null]);
        $transaction->promo_code_anomaly = $anomaly !== null;

        if ($anomaly !== null) {
            // Preserve the last valid usages for history, but exclude this transaction from promo queries.
            $this->recordAnomaly($transaction, $anomaly);

            return false;
        }

        if ($codes === []) {
            $transaction->promoCodeUsages()->delete();

            return true;
        }

        $existing = $transaction->promoCodeUsages()->get()->keyBy('code_position');

        $transaction->promoCodeUsages()->whereNotIn('code_position', array_keys($codes))->delete();

        foreach ($codes as $position => $rawCode) {
            $this->upsertUsage($transaction, $position, $rawCode, $existing->get($position));
        }

        return true;
    }

    private function upsertUsage(Transaction $transaction, int $position, string $rawCode, ?TransactionPromoCode $usage): void
    {
        $code = PromoCode::normalizeCode($rawCode);
        // Shared lock: waits while PromoCodeService changes this code's identity, so a usage is never
        // linked to a code whose code/platform is being replaced.
        $promoCode = PromoCode::query()
            ->where('platform_id', $transaction->platform_id)
            ->where('code', $code)
            ->sharedLock()
            ->first();

        $sameUsage = $usage !== null
            && $usage->code_snapshot === $code
            && (int) $usage->platform_id_snapshot === (int) $transaction->platform_id;

        // A classified usage keeps its snapshot, even after its master metadata was edited.
        if ($sameUsage && $usage->isClassified()) {
            $usage->update([
                'raw_code' => $rawCode,
                'promo_code_id' => $usage->promo_code_id ?? $promoCode?->id,
            ]);

            return;
        }

        TransactionPromoCode::updateOrCreate(
            ['transaction_id' => $transaction->id, 'code_position' => $position],
            [
                'promo_code_id' => $promoCode?->id,
                'raw_code' => $rawCode,
                'platform_id_snapshot' => $transaction->platform_id,
                'code_snapshot' => $code,
                ...($promoCode === null
                    ? PromoCodeService::EMPTY_SNAPSHOT
                    : $this->promoCodeService->snapshotCurrent($promoCode)),
            ],
        );
    }

    private function recordAnomaly(Transaction $transaction, string $reason): void
    {
        Log::channel(self::ANOMALY_LOG)->warning($reason, [
            'transaction_id' => $transaction->id,
            'trx_id' => $transaction->trx_id,
            'platform_id' => $transaction->platform_id,
            'promo_code' => $transaction->promo_code,
        ]);
    }
}
