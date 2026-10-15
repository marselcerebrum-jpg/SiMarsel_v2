<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceSummaryRequest;
use App\Http\Resources\PaymentMethodDistributionResource;
use App\Services\PaymentMethodDistributionService;
use Carbon\CarbonImmutable;

class PaymentMethodDistributionController extends Controller
{
    public function __construct(private PaymentMethodDistributionService $paymentMethodDistributionService) {}

    /**
     * Uses the same filters as the performance summary so every widget shows the same data.
     */
    public function __invoke(PerformanceSummaryRequest $request): PaymentMethodDistributionResource
    {
        $distribution = $this->paymentMethodDistributionService->distribution(
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_from'))->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $request->validated('date_to'))->startOfDay(),
            $request->validated('platform'),
        );

        return PaymentMethodDistributionResource::make($distribution);
    }
}
