<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\UnregisteredPromoCodeIndexRequest;
use App\Http\Resources\UnregisteredPromoCodeResource;
use App\Services\UnregisteredPromoCodeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnregisteredPromoCodeController extends Controller
{
    public function __construct(private UnregisteredPromoCodeService $unregisteredPromoCodeService) {}

    /**
     * The unregistered-code alert, most used first. meta.total is the number of unregistered codes.
     */
    public function index(UnregisteredPromoCodeIndexRequest $request): AnonymousResourceCollection
    {
        return UnregisteredPromoCodeResource::collection($this->unregisteredPromoCodeService->list(
            $request->validated('search'),
            $request->validated('platform_id'),
            $request->perPage(),
        ));
    }
}
