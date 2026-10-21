<?php

namespace App\Http\Requests\Promo;

class PromoSourceRequest extends PromoMasterDataRequest
{
    protected function table(): string
    {
        return 'promo_sources';
    }

    protected function routeKey(): string
    {
        return 'promoSource';
    }

    protected function nameLabel(): string
    {
        return 'Nama source';
    }
}
