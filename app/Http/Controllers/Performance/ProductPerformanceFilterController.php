<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\ProductPerformanceFilterRequest;
use App\Services\ProductPerformanceService;
use Illuminate\Http\JsonResponse;

class ProductPerformanceFilterController extends Controller
{
    public function __construct(private ProductPerformanceService $productPerformanceService) {}

    public function __invoke(ProductPerformanceFilterRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->productPerformanceService->filterOptions($request->validated('platform')),
        ]);
    }
}
