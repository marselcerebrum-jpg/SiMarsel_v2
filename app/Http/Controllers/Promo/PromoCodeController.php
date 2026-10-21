<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\PromoCodeIndexRequest;
use App\Http\Requests\Promo\StorePromoCodeRequest;
use App\Http\Requests\Promo\UpdatePromoCodeRequest;
use App\Http\Resources\PromoCodeResource;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PromoCodeController extends Controller
{
    public function __construct(private PromoCodeService $promoCodeService) {}

    public function index(PromoCodeIndexRequest $request): AnonymousResourceCollection
    {
        return PromoCodeResource::collection($this->promoCodeService->list(
            $request->validated('search'),
            $request->validated('platform_id'),
            $request->perPage(),
        ));
    }

    /**
     * Single Entry: earlier transactions with this platform and code that were not mapped yet are linked too.
     */
    public function store(StorePromoCodeRequest $request): JsonResponse
    {
        $promoCode = $this->promoCodeService->register(
            (int) $request->validated('platform_id'),
            $request->validated('code'),
            $request->validated('funnel_id'),
            $request->validated('source_id'),
        );

        return PromoCodeResource::make($this->withDetails($promoCode))
            ->additional(['message' => 'Kode promo berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(PromoCode $promoCode): PromoCodeResource
    {
        return PromoCodeResource::make($this->withDetails($promoCode));
    }

    /**
     * A new funnel or source starts a new version from now; the FE confirms that change before sending it.
     */
    public function update(UpdatePromoCodeRequest $request, PromoCode $promoCode): PromoCodeResource
    {
        $promoCode = $this->promoCodeService->update($promoCode, $request->validated());

        return PromoCodeResource::make($this->withDetails($promoCode))->additional([
            'message' => 'Kode promo berhasil diperbarui.',
        ]);
    }

    public function destroy(PromoCode $promoCode): JsonResponse
    {
        $this->promoCodeService->delete($promoCode);

        return response()->json(['message' => 'Kode promo berhasil dihapus.']);
    }

    private function withDetails(PromoCode $promoCode): PromoCode
    {
        return $promoCode
            ->load(['platform', 'funnel', 'source', 'versions' => fn ($query) => $query->orderByDesc('effective_from')])
            ->loadCount('usages');
    }
}
