<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffAttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'staff_profile_id' => $this->staff_profile_id,
            'branch_id' => $this->branch_id,
            'staff' => $this->whenLoaded('staffProfile', fn () => [
                'id' => $this->staffProfile->id,
                'name' => $this->staffProfile->professionalName(),
                'employee_number' => $this->staffProfile->employee_number,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'method' => $this->method->value,
            'status' => $this->status->value,
            'checkout_type' => $this->checkout_type?->value,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
        ];
    }
}
