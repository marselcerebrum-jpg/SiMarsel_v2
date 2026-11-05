<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The edit was based on an outdated version of the task: someone else saved it in the meantime,
 * so saving would overwrite their change.
 */
class TaskVersionConflictException extends Exception
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('Tugas telah diubah oleh pengguna lain. Muat ulang detail tugas sebelum menyimpan perubahan.');
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'current_version' => $this->currentVersion,
        ], 409);
    }
}
