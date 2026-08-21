<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'member_id' => $this->member_id,
            'membership_id' => $this->membership_id,
            'branch_id' => $this->branch_id,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'member_number' => $this->member->member_number,
                'member_code' => $this->member->member_code,
                'name' => trim($this->member->first_name.' '.$this->member->last_name),
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'method' => $this->method->value,
            'status' => $this->status->value,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
        ];
    }
}
