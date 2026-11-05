<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromoAnalysisFilterOptionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'platforms' => PlatformResource::collection($this->resource['platforms']),
            'payment_methods' => $this->resource['payment_methods'],
        ];
    }
}
