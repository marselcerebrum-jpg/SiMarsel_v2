<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceSummaryResource extends JsonResource
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
            'previous_period' => $this->resource['previous_period'],
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'kpis' => $this->resource['kpis'],
        ];
    }
}
