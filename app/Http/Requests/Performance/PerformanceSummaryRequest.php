<?php

namespace App\Http\Requests\Performance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PerformanceSummaryRequest extends FormRequest
{
    /**
     * Every authenticated account may view the performance summary.
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
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'platform' => ['nullable', 'string', Rule::exists('platforms', 'slug')],
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
            'date_format' => ':attribute harus berformat YYYY-MM-DD.',
            'after_or_equal' => ':attribute tidak boleh sebelum tanggal mulai.',
            'string' => ':attribute harus berupa teks.',
            'exists' => ':attribute tidak ditemukan.',
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
            'date_from' => 'Tanggal mulai',
            'date_to' => 'Tanggal akhir',
            'platform' => 'Platform',
        ];
    }
}
