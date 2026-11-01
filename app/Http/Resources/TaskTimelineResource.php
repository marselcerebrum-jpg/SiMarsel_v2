<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskTimelineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canViewDetail = $request->user()->can('view', $this->resource);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'division' => [
                'code_division' => $this->division->code_division,
                'name' => $this->division->name,
            ],
            'start_date' => ($this->start_date ?? $this->deadline)?->toDateString(),
            'end_date' => ($this->deadline ?? $this->start_date)?->toDateString(),
            'status' => $this->status->value,
            'is_shared' => $this->is_shared,
            'can_view_detail' => $canViewDetail,
        ];
    }
}
