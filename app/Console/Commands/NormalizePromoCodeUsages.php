<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\PromoCodeUsageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Signature('promo-codes:normalize {--chunk=500 : Transactions processed per batch}')]
#[Description('Backfill promo code usages from the raw promo_code of existing transactions')]
class NormalizePromoCodeUsages extends Command
{
    /**
     * Safe to run again: usages are upserted per transaction and code position.
     */
    public function handle(PromoCodeUsageService $promoCodeUsageService): int
    {
        $processed = 0;
        $anomalies = 0;

        Transaction::query()
            ->successful()
            ->where(fn (Builder $query) => $query->whereNotNull('promo_code')->orWhereHas('promoCodeUsages'))
            ->chunkById(max(1, (int) $this->option('chunk')), function (Collection $transactions) use ($promoCodeUsageService, &$processed, &$anomalies) {
                foreach ($transactions as $transaction) {
                    $processed++;

                    if (! $promoCodeUsageService->normalize($transaction)) {
                        $anomalies++;
                    }
                }
            });

        $this->info("{$processed} transaksi diproses, {$anomalies} anomali dicatat di storage/logs/promo-code-anomalies.log.");

        return self::SUCCESS;
    }
}
