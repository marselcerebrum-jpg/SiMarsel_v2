<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision,
            'note' => $this->note,
            'reviewer' => $this->reviewer ? ['id' => $this->reviewer->id, 'fullname' => $this->reviewer->fullname] : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
