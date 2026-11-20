<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceSalesTrendResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'filter' => $this->resource['filter'],
            'granularity' => $this->resource['granularity'],
            'default_granularity' => $this->resource['default_granularity'],
            'granularities' => $this->resource['granularities'],
            'series' => $this->resource['series'],
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'totals' => $this->resource['totals'],
            'buckets' => $this->resource['buckets'],
        ];
    }
}
