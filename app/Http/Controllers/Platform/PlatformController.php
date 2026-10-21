<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformResource;
use App\Services\PlatformService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: platforms come from the global master and cannot be changed from the API.
 */
class PlatformController extends Controller
{
    public function __construct(private PlatformService $platformService) {}

    public function index(): AnonymousResourceCollection
    {
        return PlatformResource::collection($this->platformService->list());
    }
}
