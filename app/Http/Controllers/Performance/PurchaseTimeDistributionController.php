<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceSummaryRequest;
use App\Http\Resources\PurchaseTimeDistributionResource;
use App\Services\PurchaseTimeDistributionService;
use Carbon\CarbonImmutable;

class PurchaseTimeDistributionController extends Controller
{
    public function __construct(private PurchaseTimeDistributionService $purchaseTimeDistributionService) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     */
    public function __invoke(PerformanceSummaryRequest $request): PurchaseTimeDistributionResource
    {
        $distribution = $this->purchaseTimeDistributionService->distribution(
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_from'))->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_to'))->startOfDay(),
            $request->validated('platform'),
        );

        return PurchaseTimeDistributionResource::make($distribution);
    }
}
