<?php

namespace App\Http\Resources;

use App\Models\Division;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agenda on the Calendar: its general information only, with its owner division and, for an internal agenda,
 * the divisions it is meant for. A related task is referred to by id, and whether it may be opened follows the
 * task's own access rule.
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
            'division' => $this->ownerDivision ? $this->divisionData($this->ownerDivision) : null,
            'target_divisions' => $this->is_shared
                ? []
                : $this->targetDivisions->map(fn (Division $division) => $this->divisionData($division))->all(),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'description' => $this->description,
            'task_id' => $this->task_id,
            'can_view_detail' => $this->task !== null && $request->user()->can('view', $this->task),
        ];
    }

    /**
     * @return array{code_division: string, name: string}
     */
    private function divisionData(Division $division): array
    {
        return ['code_division' => $division->code_division, 'name' => $division->name];
    }
}
