<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the rekap per kode promo.
 */
class PromoCodeSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource['code'],
            'raw_codes' => $this->resource['raw_codes'],
            'is_registered' => $this->resource['is_registered'],
            'promo_code_id' => $this->resource['promo_code_id'],
            'platform' => $this->resource['platform'],
            'funnel' => $this->resource['funnel'],
            'source' => $this->resource['source'],
            'usages' => $this->resource['usages'],
            'revenue' => $this->resource['revenue'],
            'contribution_percentage' => $this->resource['contribution_percentage'],
            'detail' => $this->resource['detail'],
        ];
    }
}
