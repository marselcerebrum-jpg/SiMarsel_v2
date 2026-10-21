<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One promo code used by a transaction, with the metadata it had when the transaction was classified.
 */
#[Fillable([
    'transaction_id',
    'promo_code_id',
    'raw_code',
    'code_position',
    'platform_id_snapshot',
    'code_snapshot',
    'funnel_id_snapshot',
    'funnel_name_snapshot',
    'source_id_snapshot',
    'source_name_snapshot',
])]
class TransactionPromoCode extends Model
{
    public const POSITION_PRIMARY = 1;

    public const POSITION_SECONDARY = 2;

    /**
     * A transaction has at most a primary code and a second (reseller) code.
     */
    public const MAX_CODES = 2;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code_position' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class, 'platform_id_snapshot');
    }

    /**
     * Whether the usage already has a funnel or source classification, which must never be overwritten.
     */
    public function isClassified(): bool
    {
        return $this->funnel_id_snapshot !== null
            || $this->source_id_snapshot !== null
            || $this->funnel_name_snapshot !== null
            || $this->source_name_snapshot !== null;
    }

    /**
     * Codes that are not in the promo code master yet, for analysis and alerts.
     *
     * @param  Builder<TransactionPromoCode>  $query
     */
    public function scopeUnmapped(Builder $query): void
    {
        $query->whereNull('promo_code_id');
    }

    /**
     * Usages without a funnel or source classification.
     *
     * @param  Builder<TransactionPromoCode>  $query
     */
    public function scopeUnclassified(Builder $query): void
    {
        $query->whereNull('funnel_id_snapshot')
            ->whereNull('source_id_snapshot')
            ->whereNull('funnel_name_snapshot')
            ->whereNull('source_name_snapshot');
    }
}
