<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskReviewResource extends JsonResource
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
            'task_id' => $this->task_id,
            'cycle' => $this->cycle,
            'decision' => $this->decision->value,
            'decision_label' => $this->decision->label(),
            'note' => $this->note,
            'reviewer' => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'fullname' => $this->reviewer->fullname,
            ] : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
