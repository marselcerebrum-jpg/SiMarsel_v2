<?php

namespace App\Http\Requests\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Enums\TransactionDateBasis;
use App\Support\TransactionList\TransactionListFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Daftar Transaksi: the main performance filter (period and platforms, FIX-04) plus the local list filters.
 * The local filters only select list rows; they never change the Dashboard figures.
 */
class TransactionListRequest extends PerformanceFilterRequest
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public const MAX_VALUES = 50;

    /**
     * Status filter values: the report statuses, plus "unavailable" for Status belum tersedia.
     */
    public const STATUSES = [
        'pending' => MarkazTransactionStatus::Pending,
        'success' => MarkazTransactionStatus::Success,
        'expired' => MarkazTransactionStatus::Expired,
        'failed' => MarkazTransactionStatus::Failed,
        'canceled' => MarkazTransactionStatus::Canceled,
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $values = ['nullable', 'array', 'max:'.self::MAX_VALUES];
        $value = ['string', 'distinct', 'max:255'];

        return [
            ...parent::rules(),
            'date_basis' => ['nullable', Rule::in(array_column(TransactionDateBasis::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:100'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'distinct', Rule::in([...array_keys(self::STATUSES), TransactionListFilter::STATUS_UNAVAILABLE])],
            'products' => $values,
            'products.*' => $value,
            'payment_methods' => $values,
            'payment_methods.*' => $value,
            'product_types' => $values,
            'product_types.*' => $value,
            'promo_codes' => [...$values, 'prohibited_if:promo,'.TransactionListFilter::PROMO_WITHOUT],
            'promo_codes.*' => ['string', 'distinct', 'max:100'],
            'promo' => ['nullable', Rule::in([TransactionListFilter::PROMO_WITH, TransactionListFilter::PROMO_WITHOUT])],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The local list filters, as TransactionListFilter constructor arguments. Blank values are left out.
     *
     * @return array<string, mixed>
     */
    public function localFilters(): array
    {
        $list = fn (string $field) => array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), (array) $this->validated($field, [])),
            fn (string $value) => $value !== '',
        ));
        $statuses = $list('statuses');
        $search = trim((string) $this->validated('search', ''));

        return [
            'dateBasis' => TransactionDateBasis::tryFrom((string) $this->validated('date_basis')) ?? TransactionDateBasis::Created,
            'search' => $search === '' ? null : $search,
            'statuses' => array_values(array_map(
                fn (string $status) => self::STATUSES[$status],
                array_intersect($statuses, array_keys(self::STATUSES)),
            )),
            'withUnavailableStatus' => in_array(TransactionListFilter::STATUS_UNAVAILABLE, $statuses, true),
            'products' => $list('products'),
            'paymentMethods' => $list('payment_methods'),
            'productTypes' => $list('product_types'),
            'promoCodes' => $list('promo_codes'),
            'promo' => $this->validated('promo'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'in' => ':attribute tidak valid.',
            'max' => ':attribute maksimal :max.',
            'min' => ':attribute minimal :min.',
            'promo_codes.prohibited_if' => 'Kode promo tidak dapat digabung dengan pilihan Tanpa Promo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'date_basis' => 'Dasar tanggal',
            'search' => 'Pencarian',
            'statuses' => 'Status',
            'statuses.*' => 'Status',
            'products' => 'Produk',
            'products.*' => 'Produk',
            'payment_methods' => 'Metode pembayaran',
            'payment_methods.*' => 'Metode pembayaran',
            'product_types' => 'Tipe produk',
            'product_types.*' => 'Tipe produk',
            'promo_codes' => 'Kode promo',
            'promo_codes.*' => 'Kode promo',
            'promo' => 'Promo',
            'per_page' => 'Jumlah per halaman',
            'page' => 'Halaman',
        ];
    }
}
