<?php

namespace App\Http\Controllers\Performance;

use App\Enums\TrendMetric;
use App\Enums\TrendPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceTrendRequest;
use App\Http\Resources\PerformanceTrendResource;
use App\Services\PerformanceTrendService;
use Carbon\CarbonImmutable;

class PerformanceTrendController extends Controller
{
    public function __construct(private PerformanceTrendService $performanceTrendService) {}

    public function __invoke(PerformanceTrendRequest $request): PerformanceTrendResource
    {
        $trend = $this->performanceTrendService->trend(
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_from'))->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_to'))->startOfDay(),
            $request->validated('platform'),
            TrendMetric::from($request->validated('metric')),
            TrendPeriod::from($request->validated('period')),
        );

        return PerformanceTrendResource::make($trend);
    }
}
