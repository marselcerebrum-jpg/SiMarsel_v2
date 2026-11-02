<?php

namespace App\Support;

use App\Models\TransactionPromoCode;

final class PromoCodePayload
{
    /** @return array<int, string>|null Codes keyed by their original, one-based position. */
    public static function codesByPosition(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value) || is_int($value)) {
            $value = [$value];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $codes = [];
        foreach ($value as $index => $code) {
            if ($code === null) {
                continue;
            }
            if (! is_string($code) && ! is_int($code)) {
                return null;
            }
            if (trim((string) $code) !== '') {
                $codes[$index + 1] = (string) $code;
            }
        }

        return $codes;
    }

    /** @param array<int, string>|null $codes */
    public static function anomalyReason(?array $codes): ?string
    {
        return match (true) {
            $codes === null => 'Format promo_code tidak dikenali.',
            $codes !== [] && array_key_last($codes) > TransactionPromoCode::MAX_CODES => 'Transaksi memiliki lebih dari dua kode promo.',
            default => null,
        };
    }
}
