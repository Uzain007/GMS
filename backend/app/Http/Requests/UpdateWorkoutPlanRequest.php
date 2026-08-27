<?php

namespace App\Http\Requests;

use App\Enums\WorkoutPlanStatus;
use Illuminate\Validation\Rule;

class UpdateWorkoutPlanRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'member_id' => ['sometimes', 'uuid', $this->tenantExists('members')],
            'trainer_staff_profile_id' => ['sometimes', 'uuid', $this->tenantExists('staff_profiles')],
            'title' => ['sometimes', 'string', 'max:160'],
            'goal' => ['nullable', 'string', 'max:240'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(WorkoutPlanStatus::class)],
            'exercises' => ['sometimes', 'array', 'min:1', 'max:60'],
            'exercises.*.name' => ['required', 'string', 'max:160'],
            'exercises.*.instructions' => ['nullable', 'string', 'max:2000'],
            'exercises.*.day_number' => ['required', 'integer', 'min:1', 'max:365'],
            'exercises.*.sort_order' => ['required', 'integer', 'min:1', 'max:200'],
            'exercises.*.target_sets' => ['nullable', 'integer', 'min:1', 'max:100'],
            'exercises.*.target_reps_min' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'exercises.*.target_reps_max' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'exercises.*.target_load_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'exercises.*.target_duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'exercises.*.rest_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
