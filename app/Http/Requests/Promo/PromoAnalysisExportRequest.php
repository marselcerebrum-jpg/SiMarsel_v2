<?php

namespace App\Http\Requests\Promo;

use App\Repositories\PromoCodeSummaryRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The Analisis Promo filter, the trend period, and the rekap order: everything the page shows,
 * so the export matches it.
 */
class PromoAnalysisExportRequest extends PromoTrendRequest
{
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
        ];
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? PromoCodeSummaryRequest::DEFAULT_SORT;
    }

    public function direction(): string
    {
        return $this->validated('direction') ?? 'desc';
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
        ];
    }
}
