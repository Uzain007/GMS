<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ],
            // tenant_role is selected from gym_user with both gym and user keys.
            'role' => $this->tenant_role ?? null,
            'home_branch_id' => $this->home_branch_id,
            'employee_number' => $this->employee_number,
            'job_title' => $this->job_title,
            'status' => $this->status->value,
            'hired_at' => $this->hired_at?->toDateString(),
            'terminated_at' => $this->terminated_at?->toDateString(),
            'permissions' => $this->permissions,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
