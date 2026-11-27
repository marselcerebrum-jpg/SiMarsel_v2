<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionStatusDistributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'filter' => $this->resource['filter'],
            'period' => $this->resource['period'],
            'date_basis' => $this->resource['date_basis'],
            'date_basis_label' => $this->resource['date_basis_label'],
            'nominal_label' => $this->resource['nominal_label'],
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'active' => $this->resource['active'],
            'comparison' => $this->resource['comparison'],
            'statuses' => $this->resource['statuses'],
            'unsuccessful' => $this->resource['unsuccessful'],
            'unknown_status' => $this->resource['unknown_status'],
        ];
    }
}
