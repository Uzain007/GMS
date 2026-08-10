<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'class_session_id' => $this->class_session_id,
            'member_id' => $this->member_id,
            'membership_id' => $this->membership_id,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'member_number' => $this->member->member_number,
                'name' => trim($this->member->first_name.' '.$this->member->last_name),
            ]),
            'session' => $this->whenLoaded('session', fn () => [
                'id' => $this->session->id,
                'title' => $this->session->title,
                'starts_at' => $this->session->starts_at?->toIso8601String(),
            ]),
            'status' => $this->status->value,
            'waitlist_sequence' => $this->waitlist_sequence,
            'booked_at' => $this->booked_at?->toIso8601String(),
            'promoted_at' => $this->promoted_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
        ];
    }
}
