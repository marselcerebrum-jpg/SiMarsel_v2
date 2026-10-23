<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One platform + normalized code found in transactions without a master yet.
 */
class UnregisteredPromoCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'raw_codes' => $this->raw_codes,
            'platform' => [
                'id' => (int) $this->platform_id,
                'slug' => $this->platform_slug,
                'name' => $this->platform_name,
            ],
            'usages_count' => (int) $this->usages_count,
            'transactions_count' => (int) $this->transactions_count,
            'first_used_on' => substr((string) $this->first_used_on, 0, 10),
            'last_used_on' => substr((string) $this->last_used_on, 0, 10),
            // Prefills the Single Entry form (POST /api/promo-codes) when the user registers the code.
            'register' => [
                'code' => $this->code,
                'platform_id' => (int) $this->platform_id,
            ],
        ];
    }
}
