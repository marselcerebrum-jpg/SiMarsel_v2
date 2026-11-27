<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\TransactionStatusDistributionRequest;
use App\Http\Resources\TransactionStatusDistributionResource;
use App\Services\TransactionCompositionService;

class TransactionStatusDistributionController extends Controller
{
    public function __construct(private TransactionCompositionService $transactionCompositionService) {}

    public function __invoke(TransactionStatusDistributionRequest $request): TransactionStatusDistributionResource
    {
        $filter = $request->filter();

        return TransactionStatusDistributionResource::make([
            'filter' => $filter->toArray(),
            ...$this->transactionCompositionService->statusDistribution($filter->period),
        ]);
    }
}
