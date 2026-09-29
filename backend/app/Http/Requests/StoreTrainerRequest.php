<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Validation\Rule;

class StoreTrainerRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['required', 'string', 'max:40'],
            'profile_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'role' => ['required', Rule::in([UserRole::GymManager->value, UserRole::Receptionist->value, UserRole::Trainer->value])],
            'employee_number' => ['nullable', 'string', 'max:64', $this->tenantUnique('staff_profiles', 'employee_number')],
            'job_title' => ['nullable', 'string', 'max:120'],
            'home_branch_id' => ['required', 'uuid', $this->tenantExists('gym_branches')->where('status', 'active')],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'phone' => trim((string) $this->input('phone')),
            'role' => $this->input('role', UserRole::Trainer->value),
            'employee_number' => $this->filled('employee_number') ? trim((string) $this->input('employee_number')) : null,
            'job_title' => $this->filled('job_title') ? trim((string) $this->input('job_title')) : null,
        ]);
    }
}
