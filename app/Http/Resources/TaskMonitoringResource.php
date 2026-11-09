<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskMonitoringResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'scope' => $this->resource['scope'],
            'as_of' => $this->resource['as_of'],
            'filters' => $this->resource['filters'],
            'period' => $this->resource['period'],
            'metrics' => $this->resource['metrics'],
            'by_priority' => $this->resource['by_priority'],
            'by_division' => $this->resource['by_division'],
        ];
    }
}
