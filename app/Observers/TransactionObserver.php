<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\PromoCodeUsageService;

class TransactionObserver
{
    public function __construct(private PromoCodeUsageService $promoCodeUsageService) {}

    /**
     * Normalize the promo code usages whenever a Markaz transaction is stored or its codes, platform, or date change.
     */
    public function saved(Transaction $transaction): void
    {
        if ($transaction->wasRecentlyCreated || $transaction->wasChanged(['promo_code', 'platform_id', 'paid_date', 'paid_time', 'status_message'])) {
            $this->promoCodeUsageService->normalize($transaction);
        }
    }
}
