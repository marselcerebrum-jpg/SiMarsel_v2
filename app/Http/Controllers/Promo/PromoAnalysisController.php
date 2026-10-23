<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\PromoAnalysisFilterRequest;
use App\Http\Resources\PromoAnalysisFilterOptionsResource;
use App\Http\Resources\PromoAnalysisSummaryResource;
use App\Http\Resources\PromoFunnelBreakdownResource;
use App\Http\Resources\PromoSourceBreakdownResource;
use App\Services\PromoAnalysisService;

class PromoAnalysisController extends Controller
{
    public function __construct(private PromoAnalysisService $promoAnalysisService) {}

    public function filters(): PromoAnalysisFilterOptionsResource
    {
        return PromoAnalysisFilterOptionsResource::make($this->promoAnalysisService->filterOptions());
    }

    public function summary(PromoAnalysisFilterRequest $request): PromoAnalysisSummaryResource
    {
        return PromoAnalysisSummaryResource::make($this->promoAnalysisService->summarize($request->promoFilter()));
    }

    public function funnels(PromoAnalysisFilterRequest $request): PromoFunnelBreakdownResource
    {
        return PromoFunnelBreakdownResource::make($this->promoAnalysisService->funnelBreakdown($request->promoFilter()));
    }

    public function sources(PromoAnalysisFilterRequest $request): PromoSourceBreakdownResource
    {
        return PromoSourceBreakdownResource::make($this->promoAnalysisService->sourceBreakdown($request->promoFilter()));
    }
}
