<?php

namespace App\Http\Requests;

use App\Enums\MemberStatus;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'home_branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            'user_id' => [
                'nullable', 'uuid', 'exists:users,id',
                $this->tenantUnique('members', 'user_id'),
            ],
            // Optional input supports imports; the service generates a tenant-local value.
            'member_number' => ['nullable', 'alpha_dash:ascii', 'max:50', $this->tenantUnique('members', 'member_number')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'status' => ['sometimes', Rule::enum(MemberStatus::class)],
            'joined_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
