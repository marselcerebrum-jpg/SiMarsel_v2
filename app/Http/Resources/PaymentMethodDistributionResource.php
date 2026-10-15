<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodDistributionResource extends JsonResource
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
            'is_empty' => $this->resource['is_empty'],
            'message' => $this->resource['message'],
            'totals' => $this->resource['totals'],
            'payment_methods' => $this->resource['payment_methods'],
            'labels' => $this->resource['labels'],
            'datasets' => $this->resource['datasets'],
        ];
    }
}
