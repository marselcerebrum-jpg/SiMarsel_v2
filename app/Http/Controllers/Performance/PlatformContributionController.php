<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceFilterRequest;
use App\Http\Resources\PlatformContributionResource;
use App\Services\PlatformContributionService;

class PlatformContributionController extends Controller
{
    public function __construct(private PlatformContributionService $platformContributionService) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     */
    public function __invoke(PerformanceFilterRequest $request): PlatformContributionResource
    {
        $contribution = $this->platformContributionService->contribution($request->filter());

        return PlatformContributionResource::make($contribution);
    }
}
