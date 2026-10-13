<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceTrendResource extends JsonResource
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
            'metric' => $this->resource['metric'],
            'period' => $this->resource['period'],
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'labels' => $this->resource['labels'],
            'datasets' => $this->resource['datasets'],
            'rows' => $this->resource['rows'],
        ];
    }
}
