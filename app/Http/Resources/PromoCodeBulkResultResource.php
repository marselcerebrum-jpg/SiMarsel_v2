<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The outcome of a Bulk Entry: the totals, then one result per submitted row with its errors.
 */
class PromoCodeBulkResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'summary' => $this->resource['summary'],
            'rows' => array_map(fn (array $row) => [
                'row' => $row['row'],
                'status' => $row['status'],
                'code' => $row['code'],
                'promo_code' => $row['promo_code'] === null ? null : PromoCodeResource::make($row['promo_code'])->resolve($request),
                'errors' => (object) $row['errors'],
            ], $this->resource['rows']),
        ];
    }
}
