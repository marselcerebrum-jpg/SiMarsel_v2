<?php

namespace App\Http\Resources;

use App\Enums\TaskStatus;
use Illuminate\Http\Request;

/**
 * A task deadline on the Calendar: the task as on the Board and Table, with its short description and
 * whether its deadline has passed while it is still active.
 */
class CalendarDeadlineResource extends TaskResource
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
            'is_overdue' => $this->status !== TaskStatus::Done && $this->deadline->lt(today()),
        ];
    }
}
