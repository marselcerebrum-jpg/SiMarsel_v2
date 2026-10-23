<?php

namespace App\Http\Requests\Promo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only checks the shape of the submission. Each row is validated on its own by PromoCodeBulkService,
 * so invalid rows never stop the valid ones from being saved.
 */
class BulkStorePromoCodeRequest extends FormRequest
{
    public const MAX_ROWS = 500;

    /**
     * Authorization is handled by PromoCodePolicy on the route.
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
            'rows' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*' => ['nullable', 'array'],
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
            'rows.required' => 'Isi minimal satu baris kode promo.',
            'rows.min' => 'Isi minimal satu baris kode promo.',
            'rows.max' => 'Maksimal :max baris kode promo dalam sekali simpan.',
            'rows.array' => 'Baris kode promo harus berupa daftar.',
            'rows.list' => 'Baris kode promo harus berupa daftar.',
            'rows.*.array' => 'Setiap baris kode promo harus berisi kolom kode, platform, funnel, dan source.',
        ];
    }
}
