<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CannotDeleteOwnAccountException extends Exception
{
    public function __construct()
    {
        parent::__construct('Anda tidak dapat menghapus akun Anda sendiri.');
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json(['message' => $this->getMessage()], 422);
    }
}
