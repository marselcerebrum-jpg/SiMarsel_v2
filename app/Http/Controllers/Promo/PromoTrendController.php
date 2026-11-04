<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\PromoTrendRequest;
use App\Http\Resources\PromoTrendResource;
use App\Services\PromoTrendService;

class PromoTrendController extends Controller
{
    public function __construct(private PromoTrendService $promoTrendService) {}

    public function __invoke(PromoTrendRequest $request): PromoTrendResource
    {
        return PromoTrendResource::make($this->promoTrendService->trend($request->promoFilter(), $request->period()));
    }
}
