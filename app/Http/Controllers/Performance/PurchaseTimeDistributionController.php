<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PurchaseTimeDistributionRequest;
use App\Http\Resources\PurchaseTimeDistributionResource;
use App\Services\PurchaseTimeDistributionService;

class PurchaseTimeDistributionController extends Controller
{
    public function __construct(private PurchaseTimeDistributionService $purchaseTimeDistributionService) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     */
    public function __invoke(PurchaseTimeDistributionRequest $request): PurchaseTimeDistributionResource
    {
        $distribution = $this->purchaseTimeDistributionService->distribution($request->filter(), $request->dayOfWeek());

        return PurchaseTimeDistributionResource::make($distribution);
    }
}
