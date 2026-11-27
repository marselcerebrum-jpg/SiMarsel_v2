<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductTypeDistributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'filter' => $this->resource['filter'],
            'period' => $this->resource['period'],
            'metric' => $this->resource['metric'],
            'default_metric' => $this->resource['default_metric'],
            'metrics' => $this->resource['metrics'],
            'date_basis' => $this->resource['date_basis'],
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'active' => $this->resource['active'],
            'comparison' => $this->resource['comparison'],
            'product_types' => $this->resource['product_types'],
        ];
    }
}
