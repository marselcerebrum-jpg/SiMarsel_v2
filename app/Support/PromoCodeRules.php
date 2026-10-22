<?php

namespace App\Support;

use App\Models\Platform;
use App\Models\PromoCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The promo code validation shared by Single Entry, Bulk Entry, and edit, so the rules can never differ.
 */
final class PromoCodeRules
{
    /**
     * The code is checked in its stored form, so " cpns26 " is a duplicate of "CPNS26".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (isset($data['code']) && is_string($data['code'])) {
            $data['code'] = PromoCode::normalizeCode($data['code']);
        }

        return $data;
    }

    /**
     * Rules for a new promo code.
     *
     * @param  Closure(): mixed  $platformId  The platform the code is checked against.
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function create(Closure $platformId): array
    {
        return [
            'code' => ['required', 'string', 'max:255', self::uniquePerPlatform($platformId)],
            'platform_id' => ['required', 'integer', Rule::exists('platforms', 'id')],
            'funnel_id' => ['nullable', 'integer', Rule::exists('funnels', 'id')],
            'source_id' => ['nullable', 'integer', Rule::exists('promo_sources', 'id')],
        ];
    }

    /**
     * Rules for editing; only the fields that are sent are validated.
     *
     * @param  Closure(): mixed  $platformId
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function update(Closure $platformId, PromoCode $current): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:255', self::uniquePerPlatform($platformId, $current)],
            'platform_id' => ['sometimes', 'required', 'integer', Rule::exists('platforms', 'id')],
            'funnel_id' => ['sometimes', 'nullable', 'integer', Rule::exists('funnels', 'id')],
            'source_id' => ['sometimes', 'nullable', 'integer', Rule::exists('promo_sources', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
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
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'code' => 'Kode promo',
            'platform_id' => 'Platform',
            'funnel_id' => 'Funnel',
            'source_id' => 'Source',
        ];
    }

    public static function duplicateMessage(string $code, int|string $platformId): string
    {
        return "Kode promo {$code} sudah terdaftar di platform ".Platform::whereKey($platformId)->value('name').'.';
    }

    /**
     * A code is unique per platform; the same code on another platform is allowed.
     *
     * @param  Closure(): mixed  $platformId
     */
    private static function uniquePerPlatform(Closure $platformId, ?PromoCode $current = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($platformId, $current) {
            $platform = $platformId();

            if (! is_string($value) || ! is_numeric($platform)) {
                return;
            }

            $taken = PromoCode::query()
                ->where('platform_id', $platform)
                ->where('code', $value)
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
                ->exists();

            if ($taken) {
                $fail(self::duplicateMessage($value, $platform));
            }
        };
    }
}
