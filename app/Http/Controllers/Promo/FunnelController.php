<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\FunnelRequest;
use App\Http\Resources\PromoMasterDataResource;
use App\Models\Funnel;
use App\Services\PromoMasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FunnelController extends Controller
{
    public function __construct(private PromoMasterDataService $promoMasterDataService) {}

    public function index(): AnonymousResourceCollection
    {
        return PromoMasterDataResource::collection($this->promoMasterDataService->list(Funnel::class));
    }

    public function store(FunnelRequest $request): JsonResponse
    {
        $funnel = $this->promoMasterDataService->create(Funnel::class, $request->validated());

        return PromoMasterDataResource::make($funnel)
            ->additional(['message' => 'Funnel berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Funnel $funnel): PromoMasterDataResource
    {
        return PromoMasterDataResource::make($funnel->loadCount('promoCodes'));
    }

    public function update(FunnelRequest $request, Funnel $funnel): PromoMasterDataResource
    {
        $funnel = $this->promoMasterDataService->update($funnel, $request->validated());

        return PromoMasterDataResource::make($funnel)->additional([
            'message' => 'Funnel berhasil diperbarui.',
        ]);
    }

    public function destroy(Funnel $funnel): JsonResponse
    {
        $this->promoMasterDataService->delete($funnel);

        return response()->json(['message' => 'Funnel berhasil dihapus.']);
    }
}
