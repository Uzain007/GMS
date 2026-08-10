<?php

namespace App\Http\Requests;

class StoreWorkoutSessionRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'workout_plan_id' => ['required', 'uuid', $this->tenantExists('workout_plans')],
            'member_id' => ['nullable', 'uuid', $this->tenantExists('members')],
            'performed_at' => ['required', 'date', 'before_or_equal:now'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'sets' => ['required', 'array', 'min:1', 'max:300'],
            'sets.*.workout_plan_exercise_id' => ['required', 'uuid', $this->tenantExists('workout_plan_exercises')],
            'sets.*.set_number' => ['required', 'integer', 'min:1', 'max:100'],
            'sets.*.reps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'sets.*.load_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'sets.*.duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'sets.*.distance_metres' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'sets.*.rpe' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
