<?php

namespace App\Http\Resources;

use App\Enums\TaskStatus;
use Illuminate\Http\Request;

class TaskDetailResource extends TaskResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $editableFields = $this->resource->editableFieldsFor($request->user());

        return [
            ...parent::toArray($request),
            'division' => [
                'id' => $this->division->id,
                'code_division' => $this->division->code_division,
                'name' => $this->division->name,
            ],
            'description' => $this->description,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'requires_review' => $this->category->requires_review,
                'evidence_required' => $this->category->evidence_required,
            ] : null,
            'platform' => $this->platform ? [
                'id' => $this->platform->id,
                'name' => $this->platform->name,
            ] : null,
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'fullname' => $this->creator->fullname,
            ] : null,
            'evidence' => TaskEvidenceResource::collection($this->whenLoaded('evidences', fn () => $this->evidences, [])),
            'history' => TaskActivityResource::collection($this->whenLoaded('activities', fn () => $this->activities, [])),
            'editable_fields' => $editableFields,
            'next_statuses' => in_array('status', $editableFields, true)
                ? collect($this->resource->nextStatusesFor($request->user()))
                    ->map(fn (TaskStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                    ->all()
                : [],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
