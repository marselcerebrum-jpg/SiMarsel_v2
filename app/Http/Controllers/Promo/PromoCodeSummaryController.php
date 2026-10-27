<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\PromoCodeSummaryRequest;
use App\Http\Requests\Promo\PromoCodeUsageRequest;
use App\Http\Resources\PromoCodeSummaryResource;
use App\Http\Resources\PromoCodeUsageDayResource;
use App\Services\PromoCodeSummaryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

class PromoCodeSummaryController extends Controller
{
    public function __construct(private PromoCodeSummaryService $promoCodeSummaryService) {}

    public function index(PromoCodeSummaryRequest $request): AnonymousResourceCollection
    {
        $summary = $this->promoCodeSummaryService->summary(
            $request->promoFilter(),
            $request->sort(),
            $request->direction(),
            $request->perPage(),
        );

        return PromoCodeSummaryResource::collection($summary['codes'])->additional(Arr::except($summary, 'codes'));
    }

    public function usages(PromoCodeUsageRequest $request): AnonymousResourceCollection
    {
        $usages = $this->promoCodeSummaryService->usages(
            $request->promoFilter(),
            $request->platformId(),
            $request->code(),
            $request->perPage(),
        );

        return PromoCodeUsageDayResource::collection($usages['days'])->additional(Arr::except($usages, 'days'));
    }
}
