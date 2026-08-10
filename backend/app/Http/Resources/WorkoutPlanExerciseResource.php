<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutPlanExerciseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id, 'workout_plan_id' => $this->workout_plan_id,
            'name' => $this->name, 'instructions' => $this->instructions,
            'day_number' => $this->day_number, 'sort_order' => $this->sort_order,
            'target_sets' => $this->target_sets, 'target_reps_min' => $this->target_reps_min,
            'target_reps_max' => $this->target_reps_max, 'target_load_grams' => $this->target_load_grams,
            'target_duration_seconds' => $this->target_duration_seconds, 'rest_seconds' => $this->rest_seconds,
        ];
    }
}
