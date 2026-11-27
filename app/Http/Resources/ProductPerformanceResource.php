<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPerformanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'period' => $this->resource['period'],
            'active' => $this->resource['active'],
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'columns' => $this->resource['columns'],
            'totals' => $this->resource['totals'],
            'contribution' => $this->resource['contribution'],
            'query' => $this->resource['query'],
            'rows' => $this->resource['rows'],
            'pagination' => $this->resource['pagination'],
        ];
    }
}
