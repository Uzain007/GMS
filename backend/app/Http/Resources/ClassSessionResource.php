<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'branch_id' => $this->branch_id,
            'trainer_staff_profile_id' => $this->trainer_staff_profile_id,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'trainer' => $this->whenLoaded('trainer', fn () => $this->trainer ? [
                'id' => $this->trainer->id,
                'name' => $this->trainer->user?->name,
            ] : null),
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'capacity' => $this->capacity,
            'booked_count' => $this->booked_count,
            'waitlist_count' => $this->waitlist_count,
            'attended_count' => $this->attended_count,
            'waitlist_enabled' => $this->waitlist_enabled,
            'booking_opens_at' => $this->booking_opens_at?->toIso8601String(),
            'booking_closes_at' => $this->booking_closes_at?->toIso8601String(),
            'status' => $this->status->value,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
