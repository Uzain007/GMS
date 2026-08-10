<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Validation\Rule;

class StoreStaffInvitationRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $staffRoles = [
            UserRole::GymOwner->value,
            UserRole::GymManager->value,
            UserRole::Receptionist->value,
            UserRole::Trainer->value,
        ];

        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            // Invitations can never escalate a tenant user to platform super admin.
            'role' => ['required', Rule::in($staffRoles)],
            'employee_number' => [
                'required', 'alpha_dash:ascii', 'max:50',
                $this->tenantUnique('staff_profiles', 'employee_number'),
            ],
            'job_title' => ['nullable', 'string', 'max:120'],
            'home_branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            'expires_in_days' => ['sometimes', 'integer', 'between:1,30'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
