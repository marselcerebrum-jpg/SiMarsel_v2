<?php

namespace App\Models;

use App\Observers\TransactionObserver;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'platform_id',
    'markaz_transaction_id',
    'markaz_user_id',
    'markaz_customer_id',
    'trx_id',
    'markaz_ref_id',
    'markaz_created_at',
    'markaz_paid_at',
    'paid_date',
    'paid_time',
    'source_status',
    'effective_status',
    'is_claim_coupon',
    'payment_method',
    'product_price',
    'voucher_discount',
    'admin_fee',
    'nominal',
    'net_nominal',
    'promo_code',
    'product',
    'product_type',
    'customer_name',
    'customer_email',
    'customer_phone',
    'status_message',
    'synced_at',
])]
#[ObservedBy(TransactionObserver::class)]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    public const STATUS_SUCCESS = 'Success';

    public const TYPE_FIRST = 'Perdana';

    public const TYPE_RENEWAL = 'Perpanjangan';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'markaz_transaction_id' => 'integer',
            'markaz_user_id' => 'integer',
            'markaz_customer_id' => 'integer',
            'markaz_ref_id' => 'integer',
            'markaz_created_at' => 'datetime',
            'markaz_paid_at' => 'datetime',
            'paid_date' => 'date',
            'source_status' => 'integer',
            'effective_status' => 'integer',
            'is_claim_coupon' => 'boolean',
            'product_price' => 'decimal:2',
            'voucher_discount' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'nominal' => 'decimal:2',
            'net_nominal' => 'decimal:2',
            'synced_at' => 'datetime',
            'promo_code' => 'array',
            'promo_code_anomaly' => 'boolean',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * The promo codes used by this transaction, one row per code position.
     */
    public function promoCodeUsages(): HasMany
    {
        return $this->hasMany(TransactionPromoCode::class)->orderBy('code_position');
    }

    /**
     * The last valid payment moment for reports; a missing paid_time counts as the start of the day.
     * Null while no valid payment date is known, for example a Pending transaction.
     */
    public function paidAt(): ?CarbonImmutable
    {
        if ($this->paid_date === null) {
            return null;
        }

        return CarbonImmutable::parse($this->paid_date->toDateString().' '.($this->paid_time ?? '00:00:00'));
    }

    /**
     * Only transactions that were paid successfully count toward performance.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeSuccessful(Builder $query): void
    {
        $query->where('status_message', self::STATUS_SUCCESS);
    }

    /** Successful transactions eligible for promo attribution, including genuine no-code transactions. */
    public function scopePromoAnalyzable(Builder $query): void
    {
        $query->successful()->where('promo_code_anomaly', false);
    }

    /**
     * First purchases (product_type = Perdana) count as new users.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeFirstPurchase(Builder $query): void
    {
        $query->where('product_type', self::TYPE_FIRST);
    }

    /**
     * Renewals (product_type = Perpanjangan) count as repeat orders.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeRenewal(Builder $query): void
    {
        $query->where('product_type', self::TYPE_RENEWAL);
    }

    /**
     * Only transactions that carry a customer email can identify a user.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeWithCustomerEmail(Builder $query): void
    {
        $query->whereNotNull('customer_email')->where('customer_email', '!=', '');
    }
}
