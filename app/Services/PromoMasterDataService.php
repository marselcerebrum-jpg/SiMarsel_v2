<?php

namespace App\Services;

use App\Exceptions\MasterDataInUseException;
use App\Models\Funnel;
use App\Models\PromoSource;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Funnel and Source masters of the promo module. Renaming never changes the name snapshots
 * already stored on promo code versions and usages.
 */
class PromoMasterDataService
{
    /**
     * @param  class-string<Funnel|PromoSource>  $model
     * @return Collection<int, Funnel|PromoSource>
     */
    public function list(string $model): Collection
    {
        return $model::withCount('promoCodes')->orderBy('id')->get();
    }

    /**
     * @param  class-string<Funnel|PromoSource>  $model
     * @param  array{name: string}  $data
     */
    public function create(string $model, array $data): Funnel|PromoSource
    {
        return $model::create($data)->loadCount('promoCodes');
    }

    /**
     * @param  array{name: string}  $data
     */
    public function update(Funnel|PromoSource $master, array $data): Funnel|PromoSource
    {
        $master->update($data);

        return $master->loadCount('promoCodes');
    }

    /**
     * Only usage by a promo code master blocks deletion; old versions and transaction snapshots keep their name.
     */
    public function delete(Funnel|PromoSource $master): void
    {
        $promoCodeCount = $master->promoCodes()->count();

        if ($promoCodeCount > 0) {
            throw new MasterDataInUseException($master instanceof Funnel ? 'Funnel' : 'Source', $promoCodeCount);
        }

        $master->delete();
    }
}
