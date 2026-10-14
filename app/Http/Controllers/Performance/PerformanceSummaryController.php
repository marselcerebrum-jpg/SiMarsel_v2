<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceSummaryRequest;
use App\Http\Resources\PerformanceSummaryResource;
use App\Services\PerformanceSummaryService;
use Carbon\CarbonImmutable;

class PerformanceSummaryController extends Controller
{
    public function __construct(private PerformanceSummaryService $performanceSummaryService) {}

    public function __invoke(PerformanceSummaryRequest $request): PerformanceSummaryResource
    {
        $summary = $this->performanceSummaryService->summarize(
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_from'))->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_to'))->startOfDay(),
            $request->validated('platform'),
        );

        return PerformanceSummaryResource::make($summary);
    }
}
