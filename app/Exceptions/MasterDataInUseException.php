<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A funnel or source cannot be deleted while promo codes still use it.
 */
class MasterDataInUseException extends Exception
{
    /**
     * @param  string  $label  "Funnel" or "Source"
     */
    public function __construct(string $label, int $promoCodeCount)
    {
        parent::__construct(
            "{$label} tidak dapat dihapus karena masih digunakan oleh {$promoCodeCount} kode promo. "
            .'Ganti '.mb_strtolower($label).' pada kode promo tersebut terlebih dahulu.'
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
