<?php

namespace App\Http\Controllers\Division;

use App\Http\Controllers\Controller;
use App\Http\Requests\Division\StoreDivisionRequest;
use App\Http\Requests\Division\UpdateDivisionRequest;
use App\Http\Resources\DivisionResource;
use App\Models\Division;
use App\Services\DivisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DivisionController extends Controller
{
    public function __construct(private DivisionService $divisionService) {}

    public function index(): AnonymousResourceCollection
    {
        return DivisionResource::collection($this->divisionService->list());
    }

    public function store(StoreDivisionRequest $request): JsonResponse
    {
        $division = $this->divisionService->create($request->validated());

        return DivisionResource::make($division)
            ->additional(['message' => 'Divisi berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Division $division): DivisionResource
    {
        return DivisionResource::make($division);
    }

    public function update(UpdateDivisionRequest $request, Division $division): DivisionResource
    {
        $division = $this->divisionService->update($division, $request->validated());

        return DivisionResource::make($division)->additional([
            'message' => 'Divisi berhasil diperbarui.',
        ]);
    }

    public function destroy(Division $division): JsonResponse
    {
        $this->divisionService->delete($division);

        return response()->json(['message' => 'Divisi berhasil dihapus.']);
    }
}
