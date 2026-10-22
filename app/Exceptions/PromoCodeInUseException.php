<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A promo code already used by transactions cannot be deleted or get another code or platform,
 * because those usages are its history.
 */
class PromoCodeInUseException extends Exception
{
    public static function delete(int $usages): self
    {
        return new self("Kode promo tidak dapat dihapus karena sudah digunakan oleh {$usages} transaksi.");
    }

    public static function identityChange(int $usages): self
    {
        return new self(
            "Kode promo dan platform tidak dapat diubah karena kode ini sudah digunakan oleh {$usages} transaksi. "
            .'Funnel dan source tetap bisa diubah; buat kode promo baru jika kodenya berbeda.'
        );
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json(['message' => $this->getMessage()], 409);
    }
}
