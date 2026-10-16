<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TopPackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'filter' => $this->resource['filter'],
            'rank_by' => $this->resource['rank_by'],
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'totals' => $this->resource['totals'],
            'packages' => $this->resource['packages'],
            'labels' => $this->resource['labels'],
            'datasets' => $this->resource['datasets'],
        ];
    }
}
