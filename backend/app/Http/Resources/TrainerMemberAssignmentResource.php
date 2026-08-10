<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainerMemberAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id,
            'trainer_staff_profile_id' => $this->trainer_staff_profile_id, 'member_id' => $this->member_id,
            'trainer' => $this->whenLoaded('trainer', fn () => ['id' => $this->trainer->id, 'name' => $this->trainer->user?->name]),
            'member' => $this->whenLoaded('member', fn () => ['id' => $this->member->id, 'member_number' => $this->member->member_number, 'name' => trim($this->member->first_name.' '.$this->member->last_name)]),
            'status' => $this->status->value, 'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(), 'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
