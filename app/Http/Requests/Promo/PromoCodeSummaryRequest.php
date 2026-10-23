<?php

namespace App\Http\Requests\Promo;

use App\Repositories\PromoCodeSummaryRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The Analisis Promo filter plus sorting and pagination for the rekap per kode promo.
 */
class PromoCodeSummaryRequest extends PromoAnalysisFilterRequest
{
    public const DEFAULT_SORT = 'revenue';

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
            'sort' => ['nullable', 'string', Rule::in(array_keys(PromoCodeSummaryRepository::SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? self::DEFAULT_SORT;
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'desc';
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
            'in' => ':attribute tidak valid.',
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
            'sort' => 'Urutan',
            'direction' => 'Arah urutan',
            'per_page' => 'Jumlah per halaman',
            'page' => 'Halaman',
        ];
    }
}
