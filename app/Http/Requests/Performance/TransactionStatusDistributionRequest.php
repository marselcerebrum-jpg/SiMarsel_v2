<?php

namespace App\Http\Requests\Performance;

use App\Enums\PeriodPreset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The composition charts read the shared period resolver, so a preset is required.
 */
class TransactionStatusDistributionRequest extends PerformanceFilterRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'preset' => ['required', Rule::in(array_column(PeriodPreset::cases(), 'value'))],
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
        ];
    }
}
