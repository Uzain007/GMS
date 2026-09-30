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
            'delivery' => [
                'event_type' => data_get($this->metadata, 'delivery.event_type'),
                'status' => data_get($this->metadata, 'delivery.status', 'unknown'),
                'updated_at' => data_get($this->metadata, 'delivery.updated_at'),
                'failure_reason' => data_get($this->metadata, 'delivery.failure_reason'),
            ],
            // The stored token hash is hidden and is never serialized.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
