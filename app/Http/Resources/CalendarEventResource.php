<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agenda on the Calendar: its general information only. A related task is referred to by id, and whether
 * it may be opened follows the task's own access rule.
 */
class CalendarEventResource extends JsonResource
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
            'division' => $this->division ? [
                'code_division' => $this->division->code_division,
                'name' => $this->division->name,
            ] : null,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'description' => $this->description,
            'task_id' => $this->task_id,
            'can_view_detail' => $this->task !== null && $request->user()->can('view', $this->task),
        ];
    }
}
