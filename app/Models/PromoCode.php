<?php

namespace App\Models;

use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Master promo code of one platform; the same code on another platform is a different promo code.
 * It has no type or active status: its funnel and source are versioned in promo_code_versions.
 */
#[Fillable(['platform_id', 'code', 'funnel_id', 'source_id'])]
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    /**
     * Codes are matched without surrounding spaces and case-insensitively, so " cpns26 " matches "CPNS26".
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * Stored normalized, so the unique (platform_id, code) index also covers case and spacing.
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeCode($value));
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PromoSource::class, 'source_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromoCodeVersion::class);
    }

    /**
     * The metadata version that is valid now; only one version is open (effective_until null) at a time.
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(PromoCodeVersion::class)->whereNull('effective_until');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(TransactionPromoCode::class);
    }
}
