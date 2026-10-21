<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
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
     * @return bool False when the value needs review (anomaly) and no usages were stored.
     */
    public function normalize(Transaction $transaction): bool
    {
        $codes = $this->codesByPosition($transaction->promo_code);

        $anomaly = match (true) {
            $codes === null => 'Format promo_code tidak dikenali.',
            $codes !== [] && array_key_last($codes) > TransactionPromoCode::MAX_CODES => 'Transaksi memiliki lebih dari dua kode promo.',
            default => null,
        };

        if ($anomaly !== null) {
            // No usages at all, so the two-code attribution is never applied to this transaction by accident.
            $transaction->promoCodeUsages()->delete();
            $this->recordAnomaly($transaction, $anomaly);

            return false;
        }

        // Most transactions have no code: one just inserted (and not updated since) has nothing to clean up either.
        if ($codes === []) {
            if (! ($transaction->wasRecentlyCreated && $transaction->getChanges() === [])) {
                $transaction->promoCodeUsages()->delete();
            }

            return true;
        }

        DB::transaction(function () use ($transaction, $codes) {
            $existing = $transaction->promoCodeUsages()->get()->keyBy('code_position');

            $transaction->promoCodeUsages()->whereNotIn('code_position', array_keys($codes))->delete();

            foreach ($codes as $position => $rawCode) {
                $this->upsertUsage($transaction, $position, $rawCode, $existing->get($position));
            }
        });

        return true;
    }

    /**
     * The codes keyed by their position in the Markaz array (1 = primary, 2 = second/reseller).
     * Empty entries keep their place but create no usage. A single string counts as position 1.
     *
     * @return array<int, string>|null Null when the value is not a code or a list of codes.
     */
    private function codesByPosition(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value) || is_int($value)) {
            $value = [$value];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $codes = [];

        foreach ($value as $index => $code) {
            if ($code === null) {
                continue;
            }

            if (! is_string($code) && ! is_int($code)) {
                return null;
            }

            if (trim((string) $code) !== '') {
                $codes[$index + 1] = (string) $code;
            }
        }

        return $codes;
    }

    private function upsertUsage(Transaction $transaction, int $position, string $rawCode, ?TransactionPromoCode $usage): void
    {
        $code = PromoCode::normalizeCode($rawCode);
        $promoCode = PromoCode::query()
            ->where('platform_id', $transaction->platform_id)
            ->where('code', $code)
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
                    : $this->promoCodeService->snapshotAt($promoCode, $transaction->paidAt())),
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
