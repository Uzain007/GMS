<?php

namespace App\Http\Requests;

use App\Enums\ClassSessionStatus;
use Illuminate\Validation\Rule;

class UpdateClassSessionRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'uuid', $this->tenantExists('gym_branches')],
            'trainer_staff_profile_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('staff_profiles')],
            'title' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'booking_opens_at' => ['sometimes', 'nullable', 'date'],
            'booking_closes_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::enum(ClassSessionStatus::class)],
            'reason' => ['required_if:status,'.ClassSessionStatus::Cancelled->value, 'nullable', 'string', 'max:500'],
        ];
    }
}
