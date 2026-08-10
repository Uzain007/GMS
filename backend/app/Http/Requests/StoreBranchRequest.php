<?php

namespace App\Http\Requests;

use App\Enums\BranchStatus;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            // Branch codes are tenant-local and indexed with gym_id for fast lookup.
            'code' => ['required', 'alpha_dash:ascii', 'max:50', $this->tenantUnique('gym_branches', 'code')],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'timezone'],
            'address' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::enum(BranchStatus::class)],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
