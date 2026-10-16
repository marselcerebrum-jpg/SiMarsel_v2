<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceFilterRequest;
use App\Http\Resources\PaymentMethodDistributionResource;
use App\Services\PaymentMethodDistributionService;

class PaymentMethodDistributionController extends Controller
{
    public function __construct(private PaymentMethodDistributionService $paymentMethodDistributionService) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     */
    public function __invoke(PerformanceFilterRequest $request): PaymentMethodDistributionResource
    {
        $distribution = $this->paymentMethodDistributionService->distribution($request->filter());

        return PaymentMethodDistributionResource::make($distribution);
    }
}
