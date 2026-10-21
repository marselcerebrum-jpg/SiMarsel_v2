<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A promo code master row. It has no type or active status by design.
 */
class PromoCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'platform' => [
                'id' => $this->platform->id,
                'slug' => $this->platform->slug,
                'name' => $this->platform->name,
            ],
            'funnel' => $this->funnel ? ['id' => $this->funnel->id, 'name' => $this->funnel->name] : null,
            'source' => $this->source ? ['id' => $this->source->id, 'name' => $this->source->name] : null,
            'usages_count' => $this->whenCounted('usages'),
            'versions' => PromoCodeVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
