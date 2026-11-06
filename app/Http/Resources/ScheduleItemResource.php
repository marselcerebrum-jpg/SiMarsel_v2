<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task or agenda on the shared schedule. Only what places it on the schedule is sent, never a task's PIC,
 * description, evidence, or history: those stay behind the task detail and its access rule.
 */
class ScheduleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isTask = $this->resource instanceof Task;
        $task = $isTask ? $this->resource : $this->task;

        return [
            'id' => $this->id,
            'type' => $isTask ? 'task' : 'event',
            'title' => $this->title,
            'division' => $this->division ? [
                'code_division' => $this->division->code_division,
                'name' => $this->division->name,
            ] : null,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => ($isTask ? $this->deadline : $this->end_date)?->toDateString(),
            'status' => $isTask ? $this->status->value : null,
            'priority' => $isTask ? $this->priority->value : null,
            'task_id' => $task?->id,
            'can_view_detail' => $task !== null && $request->user()->can('view', $task),
        ];
    }
}
