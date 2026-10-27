<?php

namespace App\Http\Requests\Promo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The detail of one code from the rekap per kode promo, with the same Analisis Promo filter as the rekap.
 */
class PromoCodeUsageRequest extends PromoAnalysisFilterRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'platform_id' => ['required', 'integer', Rule::exists('platforms', 'id')],
            'code' => ['required', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function platformId(): int
    {
        return (int) $this->validated('platform_id');
    }

    public function code(): string
    {
        return $this->validated('code');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
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
            'required' => ':attribute wajib diisi.',
            'min' => ':attribute minimal :min.',
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
            'platform_id' => 'Platform',
            'code' => 'Kode promo',
            'per_page' => 'Jumlah per halaman',
            'page' => 'Halaman',
        ];
    }
}
