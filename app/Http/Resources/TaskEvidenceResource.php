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
            'kind' => $this->resource->kind(),
            'file_name' => $this->file_name,
            'mime_type' => $this->resource->effectiveMimeType(),
            'file_size' => $this->file_size,
            'file_url' => $this->type === 'file' ? route('api.tasks.evidences.download', [$this->task_id, $this->id]) : null,
            'preview_url' => $this->resource->previewable() ? route('api.tasks.evidences.preview', [$this->task_id, $this->id]) : null,
            'url' => $this->url,
            'note' => $this->note,
            'submitter' => $this->submitter ? [
                'id' => $this->submitter->id,
                'fullname' => $this->submitter->fullname,
            ] : null,
            'can_delete' => $this->resource->deletableBy($request->user()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
