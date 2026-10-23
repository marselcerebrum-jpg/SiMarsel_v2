<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'title' => $this->title,
            'division' => [
                'code_division' => $this->division->code_division,
                'name' => $this->division->name,
            ],
            'pic' => $this->pic ? [
                'id' => $this->pic->id,
                'fullname' => $this->pic->fullname,
            ] : null,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date?->toDateString(),
            'deadline' => $this->deadline?->toDateString(),
            'requires_review' => $this->requires_review,
            'evidence_required' => $this->evidence_required,
            'can_view_detail' => $request->user()->can('view', $this->resource),
            'can_edit' => $request->user()->can('update', $this->resource),
            'can_assign' => $request->user()->can('assign', $this->resource)
                && ! isset($this->resource->lockedFieldsFor($request->user())['pic_id']),
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
