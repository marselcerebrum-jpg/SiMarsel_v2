<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One paid date of a code's usage detail, with the transactions that used the code on that date.
 */
class PromoCodeUsageDayResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->resource['date'],
            'transactions_count' => $this->resource['transactions_count'],
            'usages' => $this->resource['usages'],
            'revenue' => $this->resource['revenue'],
            'transactions' => $this->resource['transactions'],
        ];
    }
}
