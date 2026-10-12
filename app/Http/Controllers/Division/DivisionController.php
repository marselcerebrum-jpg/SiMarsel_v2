<?php

namespace App\Http\Controllers\Division;

use App\Http\Controllers\Controller;
use App\Http\Requests\Division\StoreDivisionRequest;
use App\Http\Resources\DivisionResource;
use App\Services\DivisionService;
use Illuminate\Http\JsonResponse;

class DivisionController extends Controller
{
    public function __construct(private DivisionService $divisionService) {}

    public function store(StoreDivisionRequest $request): JsonResponse
    {
        $division = $this->divisionService->create($request->validated());

        return DivisionResource::make($division)
            ->additional(['message' => 'Divisi berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }
}
