<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\ProductTypeDistributionRequest;
use App\Http\Resources\ProductTypeDistributionResource;
use App\Services\TransactionCompositionService;

class ProductTypeDistributionController extends Controller
{
    public function __construct(private TransactionCompositionService $transactionCompositionService) {}

    public function __invoke(ProductTypeDistributionRequest $request): ProductTypeDistributionResource
    {
        $filter = $request->filter();

        return ProductTypeDistributionResource::make([
            'filter' => $filter->toArray(),
            ...$this->transactionCompositionService->productTypeDistribution($filter->period, $request->metric()),
        ]);
    }
}
