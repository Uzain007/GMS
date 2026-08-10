<?php

namespace App\Http\Requests;

use App\Enums\WorkoutPlanStatus;
use Illuminate\Validation\Rule;

class UpdateWorkoutPlanRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:160'],
            'goal' => ['nullable', 'string', 'max:240'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'ends_on' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(WorkoutPlanStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:status,cancelled'],
        ];
    }
}
