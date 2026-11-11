<?php

namespace App\Http\Requests\Task;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskScheduleRequest extends FormRequest
{
    /**
     * Longest period one request may load; the Timeline asks for one or two weeks.
     */
    public const MAX_DAYS = 62;

    /**
     * Every role may see the shared schedule; what each item reveals is decided per item.
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'division' => ['nullable', 'string', Rule::exists('divisions', 'code_division')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->from()->diffInDays($this->to()) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('to', 'Periode maksimal '.self::MAX_DAYS.' hari.');
                }
            },
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
            'date_format' => ':attribute harus berformat YYYY-MM-DD.',
            'to.after_or_equal' => ':attribute tidak boleh sebelum Tanggal mulai.',
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
            'from' => 'Tanggal mulai',
            'to' => 'Tanggal akhir',
            'division' => 'Divisi',
        ];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->input('from'));
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->input('to'));
    }

    /**
     * Division code to focus on, or null for every division.
     */
    public function division(): ?string
    {
        return $this->validated('division');
    }
}
