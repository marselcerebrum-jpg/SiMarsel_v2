<?php

namespace App\Http\Requests\Performance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class PerformanceExportRequest extends PerformanceFilterRequest
{
    public const FORMATS = ['xlsx', 'csv'];

    /**
     * Uses the performance filters plus the file format.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', Rule::in(self::FORMATS)],
        ];
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
            'in' => ':attribute harus xlsx atau csv.',
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
            'format' => 'Format',
        ];
    }
}
