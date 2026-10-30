<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The metadata a promo code had from effective_from until effective_until (null = still valid).
 * Names are the snapshot saved with the version, not the current master names.
 */
class PromoCodeVersionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'platform_id' => $this->platform_id,
            'funnel' => $this->funnel_name_snapshot === null && $this->funnel_id === null
                ? null
                : ['id' => $this->funnel_id, 'name' => $this->funnel_name_snapshot],
            'source' => $this->source_name_snapshot === null && $this->source_id === null
                ? null
                : ['id' => $this->source_id, 'name' => $this->source_name_snapshot],
            'effective_from' => $this->effectiveTime($this->effective_from),
            'effective_until' => $this->effectiveTime($this->effective_until),
        ];
    }

    private function effectiveTime(?CarbonInterface $time): ?string
    {
        return $time === null ? null : ($time->micro === 0
            ? $time->toIso8601String()
            : $time->format('Y-m-d\TH:i:s.uP'));
    }
}
