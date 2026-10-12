<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    public const STATUS_SUCCESS = 'Success';

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
     * Only transactions that were paid successfully count toward performance.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeSuccessful(Builder $query): void
    {
        $query->where('status_message', self::STATUS_SUCCESS);
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
