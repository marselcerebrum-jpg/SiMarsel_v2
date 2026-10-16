<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\TopPackageRequest;
use App\Http\Resources\TopPackageResource;
use App\Services\TopPackageService;

class TopPackageController extends Controller
{
    public function __construct(private TopPackageService $topPackageService) {}

    public function byRevenue(TopPackageRequest $request): TopPackageResource
    {
        return $this->top($request, TopPackageService::RANK_BY_REVENUE);
    }

    public function byTransactions(TopPackageRequest $request): TopPackageResource
    {
        return $this->top($request, TopPackageService::RANK_BY_TRANSACTIONS);
    }

    private function top(TopPackageRequest $request, string $rankBy): TopPackageResource
    {
        $month = $request->validated('month');
        $limit = $request->validated('limit');

        $top = $this->topPackageService->top(
            $request->validated('platform'),
            (int) $request->validated('year'),
            $month === null ? null : (int) $month,
            $limit === null ? TopPackageService::DEFAULT_LIMIT : (int) $limit,
            $rankBy,
        );

        return TopPackageResource::make($top);
    }
}
