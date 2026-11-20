<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceSalesTrendRequest;
use App\Http\Resources\PerformanceSalesTrendResource;
use App\Services\PerformanceTrendService;

class PerformanceSalesTrendController extends Controller
{
    public function __construct(private PerformanceTrendService $performanceTrendService) {}

    public function __invoke(PerformanceSalesTrendRequest $request): PerformanceSalesTrendResource
    {
        return PerformanceSalesTrendResource::make(
            $this->performanceTrendService->salesTrend($request->filter(), $request->granularity()),
        );
    }
}
