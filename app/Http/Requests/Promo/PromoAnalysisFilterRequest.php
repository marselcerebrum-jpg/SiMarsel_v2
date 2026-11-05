<?php

namespace App\Http\Requests\Promo;

use App\Http\Requests\Performance\PerformanceFilterRequest;
use App\Support\PromoAnalysisFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * The Analisis Promo filter, shared by every promo analysis endpoint. Platforms and periods follow the
 * performance filter, limited to two period modes: date range, or month and year. None means the current month.
 */
class PromoAnalysisFilterRequest extends PerformanceFilterRequest
{
    private const YEAR_MODE_FIELDS = ['years', 'year_from', 'year_to'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...Arr::except(parent::rules(), ['years.*']),
            ...array_fill_keys(self::YEAR_MODE_FIELDS, ['prohibited']),
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'distinct', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Only one period mode may be active at a time.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $dateRange = $this->filled('date_from') || $this->filled('date_to');
                $month = $this->filled('year') || $this->filled('month');

                if ($dateRange && $month) {
                    $validator->errors()->add('filter', 'Pilih salah satu filter periode saja: rentang tanggal, atau bulan & tahun.');
                }
            },
        ];
    }

    public function promoFilter(): PromoAnalysisFilter
    {
        return new PromoAnalysisFilter(
            $this->filter(),
            array_values(array_map('trim', (array) $this->input('payment_methods', []))),
            $this->input('search'),
        );
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'prohibited' => 'Filter tahun tidak tersedia di Analisis Promo. Pilih rentang tanggal, atau bulan & tahun.',
            'max' => ':attribute maksimal :max karakter.',
        ];
    }

    /**
     * Get custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'payment_methods' => 'Metode pembayaran',
            'payment_methods.*' => 'Metode pembayaran',
            'search' => 'Pencarian kode promo',
        ];
    }
}
