<?php

namespace App\Http\Requests\Promo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdatePromoCodeRequest extends StorePromoCodeRequest
{
    /**
     * Only the fields that are sent will be validated and updated; funnel_id or source_id null clears it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:255', $this->uniquePerPlatform()],
            'platform_id' => ['sometimes', 'required', 'integer', Rule::exists('platforms', 'id')],
            'funnel_id' => ['sometimes', 'nullable', 'integer', Rule::exists('funnels', 'id')],
            'source_id' => ['sometimes', 'nullable', 'integer', Rule::exists('promo_sources', 'id')],
        ];
    }
}
