<?php

namespace App\Http\Requests\Promo;

use App\Models\PromoCode;
use App\Support\PromoCodeRules;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdatePromoCodeRequest extends StorePromoCodeRequest
{
    /**
     * Only the fields that are sent will be validated and updated; funnel_id or source_id null clears it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var PromoCode $promoCode */
        $promoCode = $this->route('promoCode');

        return PromoCodeRules::update(fn () => $this->input('platform_id', $promoCode->platform_id), $promoCode);
    }
}
