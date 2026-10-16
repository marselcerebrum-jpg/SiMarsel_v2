<?php

namespace App\Http\Requests\Performance;

use Illuminate\Contracts\Validation\ValidationRule;

class TopPackageRequest extends ProductPerformanceRequest
{
    public const MAX_LIMIT = 50;

    /**
     * Same filters as product performance, plus how many packages to return.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_LIMIT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'limit.between' => 'Jumlah paket harus antara 1 sampai :max.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'limit' => 'Jumlah paket',
        ];
    }
}
