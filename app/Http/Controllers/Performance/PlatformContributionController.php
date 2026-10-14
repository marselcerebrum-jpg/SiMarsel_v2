<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceSummaryRequest;
use App\Http\Resources\PlatformContributionResource;
use App\Services\PlatformContributionService;
use Carbon\CarbonImmutable;

class PlatformContributionController extends Controller
{
    public function __construct(private PlatformContributionService $platformContributionService) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     */
    public function __invoke(PerformanceSummaryRequest $request): PlatformContributionResource
    {
        $contribution = $this->platformContributionService->contribution(
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_from'))->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_to'))->startOfDay(),
            $request->validated('platform'),
        );

        return PlatformContributionResource::make($contribution);
    }
}
