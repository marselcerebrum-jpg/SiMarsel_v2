<?php

namespace App\Http\Requests\Performance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductPerformanceRequest extends FormRequest
{
    /**
     * Every authenticated account may view product performance.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => ['nullable', 'string', Rule::exists('platforms', 'slug')],
            'year' => ['required', 'integer', 'digits:4', 'min:2000'],
            'month' => ['nullable', 'integer', 'between:1,12'],
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
            'required' => ':attribute wajib diisi.',
            'string' => ':attribute harus berupa teks.',
            'integer' => ':attribute harus berupa angka.',
            'exists' => ':attribute tidak ditemukan.',
            'year.digits' => 'Tahun harus 4 digit, misalnya 2026.',
            'year.min' => 'Tahun minimal 2000.',
            'month.between' => 'Bulan harus antara 1 sampai 12.',
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
            'platform' => 'Platform',
            'year' => 'Tahun',
            'month' => 'Bulan',
        ];
    }
}
