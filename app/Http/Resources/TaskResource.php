<?php

namespace App\Http\Resources;

use App\Models\Account;
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
            'pics' => $this->pics->map(fn (Account $pic) => ['id' => $pic->id, 'fullname' => $pic->fullname])->values()->all(),
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date?->toDateString(),
            'deadline' => $this->deadline?->toDateString(),
            'requires_review' => $this->requires_review,
            'evidence_required' => $this->evidence_required,
            'evidence_count' => $this->whenCounted('evidences'),
            'can_view_detail' => $request->user()->can('view', $this->resource),
            'can_edit' => $request->user()->can('update', $this->resource),
            'can_assign' => $this->resource->assignmentLockFor($request->user()) === null,
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
