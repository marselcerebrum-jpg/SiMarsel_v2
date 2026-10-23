<?php

namespace App\Http\Resources;

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
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'creator' => $this->creator ? [
                'id' => $this->creator->id,
                'fullname' => $this->creator->fullname,
            ] : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
