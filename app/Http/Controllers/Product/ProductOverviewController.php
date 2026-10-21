<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductFilterRequest;
use App\Http\Requests\Product\ProductOverviewRequest;
use App\Services\ProductOverviewService;
use Illuminate\Http\JsonResponse;

class ProductOverviewController extends Controller
{
    public function __construct(private ProductOverviewService $productOverviewService) {}

    public function filters(ProductFilterRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->productOverviewService->filters($request->platform()),
        ]);
    }

    public function topByRevenue(ProductOverviewRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->productOverviewService->topByRevenue(
                $request->platform(),
                $request->year(),
                $request->month(),
                $request->limit(ProductOverviewService::DEFAULT_LIMIT),
            ),
        ]);
    }

    public function topByVolume(ProductOverviewRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->productOverviewService->topByVolume(
                $request->platform(),
                $request->year(),
                $request->month(),
                $request->limit(ProductOverviewService::DEFAULT_LIMIT),
            ),
        ]);
    }

    public function performance(ProductOverviewRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->productOverviewService->performance(
                $request->platform(),
                $request->year(),
                $request->month(),
            ),
        ]);
    }
}
