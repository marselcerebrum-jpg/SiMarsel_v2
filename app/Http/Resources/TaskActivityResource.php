<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskActivityResource extends JsonResource
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
            'action' => $this->action,
            'action_label' => $this->resource->actionLabel(),
            'description' => $this->resource->description(),
            'changes' => $this->changes ?? (object) [],
            'actor' => $this->resource->actorSummary(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
