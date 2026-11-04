<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The export would not fit in one Excel worksheet, so the user has to narrow the filter.
 */
class ExportTooLargeException extends Exception
{
    public static function rows(string $sheet, int $rows, int $maxRows): self
    {
        return new self(
            "Data terlalu besar untuk diekspor: sheet {$sheet} berisi {$rows} baris, maksimal {$maxRows} baris. "
            .'Persempit periode atau filter, lalu coba lagi.'
        );
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json(['message' => $this->getMessage()], 422);
    }
}
