<?php

namespace App\Http\Controllers\Performance;

use App\Enums\TrendMetric;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceTrendRequest;
use App\Http\Resources\PerformanceTrendResource;
use App\Services\PerformanceTrendService;

class PerformanceTrendController extends Controller
{
    public function __construct(private PerformanceTrendService $performanceTrendService) {}

    public function __invoke(PerformanceTrendRequest $request): PerformanceTrendResource
    {
        $trend = $this->performanceTrendService->trend(
            $request->filter(),
            TrendMetric::from($request->validated('metric')),
            $request->period(),
        );

        return PerformanceTrendResource::make($trend);
    }
}
