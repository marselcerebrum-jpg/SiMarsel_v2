<?php

namespace App\Http\Requests\Promo;

class FunnelRequest extends PromoMasterDataRequest
{
    protected function table(): string
    {
        return 'funnels';
    }

    protected function routeKey(): string
    {
        return 'funnel';
    }

    protected function nameLabel(): string
    {
        return 'Nama funnel';
    }
}
