<?php

namespace App\Http\Requests;

class UpdateMemberSelfRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Self-service can update contact/profile fields only. Tenant,
            // identity links, membership state and member number stay staff-owned.
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
        ];
    }
}
