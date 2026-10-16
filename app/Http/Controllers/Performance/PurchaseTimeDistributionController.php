<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceFilterRequest;
use App\Http\Resources\PurchaseTimeDistributionResource;
use App\Services\PurchaseTimeDistributionService;

class PurchaseTimeDistributionController extends Controller
{
    public function __construct(private PurchaseTimeDistributionService $purchaseTimeDistributionService) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     */
    public function __invoke(PerformanceFilterRequest $request): PurchaseTimeDistributionResource
    {
        $distribution = $this->purchaseTimeDistributionService->distribution($request->filter());

        return PurchaseTimeDistributionResource::make($distribution);
    }
}
