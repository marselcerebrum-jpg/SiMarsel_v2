<?php

namespace App\Http\Requests\Performance;

use App\Services\ProductPerformanceService;
use App\Support\ProductPerformanceQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The Dashboard filter (FIX-04 preset, required here) plus the table's own search, sort, and page.
 * The table controls never change the main filter, so the contribution divisor stays the same.
 */
class ProductPerformanceRequest extends PerformanceFilterRequest
{
    public const MAX_PER_PAGE = 100;

    public const MAX_SEARCH_LENGTH = 100;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['preset'] = ['required', ...array_diff((array) $rules['preset'], ['nullable'])];

        return [
            ...$rules,
            'search' => ['nullable', 'string', 'max:'.self::MAX_SEARCH_LENGTH],
            'sort' => ['nullable', Rule::in(ProductPerformanceService::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'preset.required' => ':attribute wajib diisi.',
            'search.max' => ':attribute maksimal :max karakter.',
            'sort.in' => ':attribute tidak valid.',
            'direction.in' => ':attribute tidak valid.',
            'min' => ':attribute minimal :min.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'search' => 'Pencarian produk',
            'sort' => 'Urutan',
            'direction' => 'Arah urutan',
            'page' => 'Halaman',
            'per_page' => 'Jumlah per halaman',
        ];
    }

    public function tableQuery(): ProductPerformanceQuery
    {
        $sort = (string) ($this->input('sort') ?: ProductPerformanceService::DEFAULT_SORT);

        return new ProductPerformanceQuery(
            search: trim((string) $this->input('search')),
            sort: $sort,
            direction: (string) ($this->input('direction') ?: ($sort === 'product' ? 'asc' : 'desc')),
            page: (int) ($this->input('page') ?: 1),
            perPage: (int) ($this->input('per_page') ?: ProductPerformanceQuery::DEFAULT_PER_PAGE),
        );
    }
}
