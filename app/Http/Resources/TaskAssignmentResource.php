<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PIC assignment, active or released, for the task's assignment history.
 */
class TaskAssignmentResource extends JsonResource
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
            'account' => $this->person($this->account),
            'assigned_by' => $this->person($this->assigner),
            'assigned_at' => $this->assigned_at?->toIso8601ZuluString(),
            'unassigned_by' => $this->person($this->unassigner),
            'unassigned_at' => $this->unassigned_at?->toIso8601ZuluString(),
            'is_active' => $this->resource->isActive(),
            'note' => $this->note,
        ];
    }

    /**
     * @return array{id: int, fullname: string}|null
     */
    private function person(?Account $account): ?array
    {
        return $account ? ['id' => $account->id, 'fullname' => $account->fullname] : null;
    }
}
