<?php

namespace App\Http\Requests;

use App\Enums\BranchStatus;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => [
                'sometimes', 'alpha_dash:ascii', 'max:50',
                $this->tenantUnique('gym_branches', 'code')->ignore((string) $this->route('branch')),
            ],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'timezone'],
            'address' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::enum(BranchStatus::class)],
            'is_primary' => ['sometimes', 'boolean'],
            // Administrative state changes retain an auditable business reason.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
