<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'home_branch_id' => $this->home_branch_id,
            'user_id' => $this->user_id,
            'member_number' => $this->member_number,
            'member_code' => $this->member_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'status' => $this->status->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            // Metadata is already selected through a tenant-scoped model query.
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
