<?php

namespace App\Http\Requests\Task;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskCalendarRequest extends FormRequest
{
    /**
     * Every role may open the Calendar; agendas and deadlines are scoped per account.
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
            'month' => ['required', 'date_format:Y-m'],
            'division' => ['nullable', 'string', Rule::exists('divisions', 'code_division')],
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
            'month.date_format' => ':attribute harus berformat YYYY-MM.',
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
            'month' => 'Bulan',
            'division' => 'Divisi',
        ];
    }

    /**
     * First day of the requested month.
     */
    public function month(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->validated('month'));
    }

    /**
     * Division code to focus on, or null for every division.
     */
    public function division(): ?string
    {
        return $this->validated('division');
    }
}
