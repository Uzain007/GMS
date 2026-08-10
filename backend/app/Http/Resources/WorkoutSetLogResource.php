<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutSetLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id,
            'workout_plan_exercise_id' => $this->workout_plan_exercise_id,
            'exercise_name' => $this->whenLoaded('exercise', fn () => $this->exercise->name),
            'set_number' => $this->set_number, 'reps' => $this->reps,
            'load_grams' => $this->load_grams, 'duration_seconds' => $this->duration_seconds,
            'distance_metres' => $this->distance_metres, 'rpe' => $this->rpe,
        ];
    }
}
