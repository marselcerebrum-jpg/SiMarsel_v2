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
    'markaz_user_id',
    'trx_id',
    'paid_date',
    'paid_time',
    'payment_method',
    'nominal',
    'promo_code',
    'product',
    'product_type',
    'customer_email',
    'status_message',
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
            'paid_date' => 'date',
            'nominal' => 'decimal:2',
            'promo_code' => 'array',
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
     * The moment the transaction was paid; a missing paid_time counts as the start of the day.
     */
    public function paidAt(): CarbonImmutable
    {
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
