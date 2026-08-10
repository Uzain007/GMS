<?php

namespace App\Http\Requests;

use App\Enums\StaffStatus;
use App\Enums\UserRole;
use Illuminate\Validation\Rule;

class UpdateStaffProfileRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in([
                UserRole::GymOwner->value,
                UserRole::GymManager->value,
                UserRole::Receptionist->value,
                UserRole::Trainer->value,
            ])],
            'employee_number' => [
                'sometimes', 'alpha_dash:ascii', 'max:50',
                $this->tenantUnique('staff_profiles', 'employee_number')->ignore((string) $this->route('staff')),
            ],
            'job_title' => ['nullable', 'string', 'max:120'],
            'home_branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            'status' => ['sometimes', Rule::enum(StaffStatus::class)],
            'hired_at' => ['nullable', 'date'],
            'terminated_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
            'permissions' => ['nullable', 'array'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
