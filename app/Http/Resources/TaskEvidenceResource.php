<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'type' => $this->type,
            'file_name' => $this->file_name,
            'file_url' => $this->type === 'file' ? route('api.tasks.evidences.download', [$this->task_id, $this->id]) : null,
            'url' => $this->url,
            'note' => $this->note,
            'submitter' => $this->submitter ? [
                'id' => $this->submitter->id,
                'fullname' => $this->submitter->fullname,
            ] : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
