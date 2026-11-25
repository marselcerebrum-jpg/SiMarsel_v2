<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\ProductPerformanceRequest;
use App\Http\Resources\ProductPerformanceResource;
use App\Services\ProductPerformanceService;

class ProductPerformanceController extends Controller
{
    public function __construct(private ProductPerformanceService $productPerformanceService) {}

    /**
     * Performa Produk (FIX-11): one page of the product table for the Dashboard filter.
     */
    public function __invoke(ProductPerformanceRequest $request): ProductPerformanceResource
    {
        $table = $this->productPerformanceService->table($request->filter()->period, $request->tableQuery());

        return ProductPerformanceResource::make($table);
    }
}
