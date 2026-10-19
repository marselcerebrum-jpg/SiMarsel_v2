<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceFilterRequest;
use App\Http\Resources\PerformanceSummaryResource;
use App\Services\PerformanceSummaryService;

class PerformanceSummaryController extends Controller
{
    public function __construct(private PerformanceSummaryService $performanceSummaryService) {}

    public function __invoke(PerformanceFilterRequest $request): PerformanceSummaryResource
    {
        $summary = $this->performanceSummaryService->summarize($request->filter());

        return PerformanceSummaryResource::make($summary);
    }
}
