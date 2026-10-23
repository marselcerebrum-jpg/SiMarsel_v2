<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The same account sent the same task again while the first submission was still fresh,
 * e.g. a double click or a resent request, so no second task is created.
 */
class DuplicateTaskSubmissionException extends Exception
{
    public function __construct()
    {
        parent::__construct('Tugas yang sama baru saja dikirim. Periksa daftar tugas sebelum mengirim ulang.');
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json(['message' => $this->getMessage()], 409);
    }
}
