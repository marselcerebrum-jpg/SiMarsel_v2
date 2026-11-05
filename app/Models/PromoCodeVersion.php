<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The funnel and source a promo code had from effective_from until effective_until (null = still valid).
 */
#[Fillable([
    'promo_code_id',
    'platform_id',
    'code',
    'funnel_id',
    'funnel_name_snapshot',
    'source_id',
    'source_name_snapshot',
    'effective_from',
    'effective_until',
])]
class PromoCodeVersion extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PromoSource::class, 'source_id');
    }

    /**
     * The version that was valid at the given moment: effective_from <= moment < effective_until.
     *
     * @param  Builder<PromoCodeVersion>  $query
     */
    public function scopeEffectiveAt(Builder $query, CarbonInterface $moment): void
    {
        $at = $moment->format($this->getDateFormat());

        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $at));
    }
}
