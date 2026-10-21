<?php

namespace App\Http\Requests\Promo;

use App\Models\Platform;
use App\Models\PromoCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromoCodeRequest extends FormRequest
{
    /**
     * Authorization is handled by PromoCodePolicy on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The code is checked in its stored form, so " cpns26 " is a duplicate of "CPNS26".
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => PromoCode::normalizeCode($this->input('code'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', $this->uniquePerPlatform()],
            'platform_id' => ['required', 'integer', Rule::exists('platforms', 'id')],
            'funnel_id' => ['nullable', 'integer', Rule::exists('funnels', 'id')],
            'source_id' => ['nullable', 'integer', Rule::exists('promo_sources', 'id')],
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
            'max' => ':attribute maksimal :max karakter.',
            'integer' => ':attribute tidak valid.',
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
            'code' => 'Kode promo',
            'platform_id' => 'Platform',
            'funnel_id' => 'Funnel',
            'source_id' => 'Source',
        ];
    }

    /**
     * A code is unique per platform; the same code on another platform is allowed.
     */
    protected function uniquePerPlatform(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $current = $this->route('promoCode');
            $platformId = $this->input('platform_id', $current instanceof PromoCode ? $current->platform_id : null);

            if (! is_string($value) || ! is_numeric($platformId)) {
                return;
            }

            $taken = PromoCode::query()
                ->where('platform_id', $platformId)
                ->where('code', $value)
                ->when($current instanceof PromoCode, fn ($query) => $query->whereKeyNot($current->getKey()))
                ->exists();

            if ($taken) {
                $fail("Kode promo {$value} sudah terdaftar di platform ".Platform::whereKey($platformId)->value('name').'.');
            }
        };
    }
}
