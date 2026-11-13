<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A task's owning division (tasks.division_id) cannot also be stored as one of its collaborating divisions.
 */
class PrimaryDivisionCollaboratorException extends Exception
{
    public function __construct()
    {
        parent::__construct('Divisi utama tugas tidak dapat ditambahkan sebagai divisi kolaborator.');
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['division_id' => [$this->getMessage()]],
        ], 422);
    }
}
