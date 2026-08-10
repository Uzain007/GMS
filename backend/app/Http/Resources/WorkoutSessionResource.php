<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id, 'workout_plan_id' => $this->workout_plan_id,
            'member_id' => $this->member_id,
            'plan' => $this->whenLoaded('plan', fn () => ['id' => $this->plan->id, 'title' => $this->plan->title]),
            'member' => $this->whenLoaded('member', fn () => ['id' => $this->member->id, 'member_number' => $this->member->member_number, 'name' => trim($this->member->first_name.' '.$this->member->last_name)]),
            'performed_at' => $this->performed_at?->toIso8601String(), 'duration_seconds' => $this->duration_seconds,
            'notes' => $this->notes, 'sets' => WorkoutSetLogResource::collection($this->whenLoaded('sets')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
