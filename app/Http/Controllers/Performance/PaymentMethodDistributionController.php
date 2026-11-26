<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceFilterRequest;
use App\Http\Resources\PaymentMethodDistributionResource;
use App\Services\PaymentMethodComparisonService;
use App\Services\PaymentMethodDistributionService;

class PaymentMethodDistributionController extends Controller
{
    public function __construct(
        private PaymentMethodDistributionService $paymentMethodDistributionService,
        private PaymentMethodComparisonService $paymentMethodComparisonService,
    ) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     * With a `preset`, the response also carries the per-method comparison table (FIX-10).
     */
    public function __invoke(PerformanceFilterRequest $request): PaymentMethodDistributionResource
    {
        $filter = $request->filter();

        return PaymentMethodDistributionResource::make([
            ...$this->paymentMethodDistributionService->distribution($filter),
            'method_comparison' => $filter->period === null
                ? null
                : $this->paymentMethodComparisonService->compare($filter->period),
        ]);
    }
}
