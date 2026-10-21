<?php

namespace App\Http\Requests\Product;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductOverviewRequest extends FormRequest
{
    public const MAX_LIMIT = 50;

    /**
     * Every authenticated account may view the product overview.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Source App, Year, and Month filters shared by every product overview card.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => ['nullable', 'string', Rule::exists('platforms', 'slug')],
            'year' => ['required', 'integer', 'digits:4', 'min:2000'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_LIMIT],
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
            'limit.between' => 'Jumlah paket harus antara 1 sampai :max.',
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
            'limit' => 'Jumlah paket',
        ];
    }

    public function platform(): ?string
    {
        return $this->validated('platform');
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function month(): ?int
    {
        $month = $this->validated('month');

        return $month === null ? null : (int) $month;
    }

    public function limit(int $default): int
    {
        $limit = $this->validated('limit');

        return $limit === null ? $default : (int) $limit;
    }
}
