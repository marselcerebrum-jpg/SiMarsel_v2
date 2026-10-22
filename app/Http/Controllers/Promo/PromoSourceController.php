<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\PromoSourceRequest;
use App\Http\Resources\PromoMasterDataResource;
use App\Models\PromoSource;
use App\Services\PromoMasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PromoSourceController extends Controller
{
    public function __construct(private PromoMasterDataService $promoMasterDataService) {}

    public function index(): AnonymousResourceCollection
    {
        return PromoMasterDataResource::collection($this->promoMasterDataService->list(PromoSource::class));
    }

    public function store(PromoSourceRequest $request): JsonResponse
    {
        $promoSource = $this->promoMasterDataService->create(PromoSource::class, $request->validated());

        return PromoMasterDataResource::make($promoSource)
            ->additional(['message' => 'Source berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(PromoSource $promoSource): PromoMasterDataResource
    {
        return PromoMasterDataResource::make($promoSource->loadCount('promoCodes'));
    }

    public function update(PromoSourceRequest $request, PromoSource $promoSource): PromoMasterDataResource
    {
        $promoSource = $this->promoMasterDataService->update($promoSource, $request->validated());

        return PromoMasterDataResource::make($promoSource)->additional([
            'message' => 'Source berhasil diperbarui.',
        ]);
    }

    public function destroy(PromoSource $promoSource): JsonResponse
    {
        $this->promoMasterDataService->delete($promoSource);

        return response()->json(['message' => 'Source berhasil dihapus.']);
    }
}
