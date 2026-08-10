<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'home_branch_id' => $this->home_branch_id,
            'email' => $this->email,
            'role' => $this->role->value,
            'employee_number' => $this->employee_number,
            'job_title' => $this->job_title,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            // The stored token hash is hidden and is never serialized.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
