<?php

namespace App\Http\Requests\Promo;

use App\Support\PromoCodeGroup;
use Closure;
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
            'group' => ['nullable', 'string', 'max:1000', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && PromoCodeGroup::decode($value) === null) {
                    $fail('Grup klasifikasi tidak valid. Buka detail dari baris rekap kode promo.');
                }
            }],
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

    /**
     * The funnel and source snapshot group of the clicked rekap row, or null for every usage of the code.
     *
     * @return array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null
     */
    public function group(): ?array
    {
        $group = $this->validated('group');

        return $group === null ? null : PromoCodeGroup::decode($group);
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
            'group' => 'Grup klasifikasi',
            'per_page' => 'Jumlah per halaman',
            'page' => 'Halaman',
        ];
    }
}
