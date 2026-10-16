<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\TopPackageRequest;
use App\Http\Resources\TopPackageResource;
use App\Services\TopPackageService;

class TopPackageController extends Controller
{
    public function __construct(private TopPackageService $topPackageService) {}

    public function __invoke(TopPackageRequest $request): TopPackageResource
    {
        $month = $request->validated('month');
        $limit = $request->validated('limit');

        $top = $this->topPackageService->top(
            $request->validated('platform'),
            (int) $request->validated('year'),
            $month === null ? null : (int) $month,
            $limit === null ? TopPackageService::DEFAULT_LIMIT : (int) $limit,
        );

        return TopPackageResource::make($top);
    }
}
