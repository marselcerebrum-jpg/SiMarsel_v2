<?php

namespace App\Services;

use App\Repositories\UnregisteredPromoCodeRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The unregistered-code alert. Registering a code from the alert goes through PromoCodeService::register()
 * (POST /api/promo-codes), which links these usages to the new master.
 */
class UnregisteredPromoCodeService
{
    public function __construct(private UnregisteredPromoCodeRepository $unregisteredPromoCodeRepository) {}

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function list(?string $search, ?int $platformId, int $perPage): LengthAwarePaginator
    {
        $page = $this->unregisteredPromoCodeRepository->paginate($search, $platformId, $perPage);

        $rawCodes = $this->unregisteredPromoCodeRepository->rawCodes(
            $page->getCollection()
                ->map(fn (object $row) => ['platform_id' => (int) $row->platform_id, 'code' => $row->code])
                ->all(),
        );

        $page->getCollection()->each(function (object $row) use ($rawCodes) {
            $row->raw_codes = $rawCodes->get($row->platform_id.'|'.$row->code, []);
        });

        return $page;
    }
}
