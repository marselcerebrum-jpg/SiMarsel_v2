<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
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
            'fullname' => $this->fullname,
            'username' => $this->username,
            'role' => [
                'code_role' => $this->role->code_role,
                'name' => $this->role->name,
            ],
            'division' => $this->division ? [
                'code_division' => $this->division->code_division,
                'name' => $this->division->name,
            ] : null,
        ];
    }
}
