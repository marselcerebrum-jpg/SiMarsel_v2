<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\ProductPerformanceRequest;
use App\Http\Resources\ProductPerformanceResource;
use App\Services\ProductPerformanceService;

class ProductPerformanceController extends Controller
{
    public function __construct(private ProductPerformanceService $productPerformanceService) {}

    public function __invoke(ProductPerformanceRequest $request): ProductPerformanceResource
    {
        $month = $request->validated('month');

        $summary = $this->productPerformanceService->summarize(
            $request->validated('platform'),
            (int) $request->validated('year'),
            $month === null ? null : (int) $month,
        );

        return ProductPerformanceResource::make($summary);
    }
}
