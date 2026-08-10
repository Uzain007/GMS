<?php

namespace App\Http\Requests;

use App\Enums\MembershipStatus;
use Illuminate\Validation\Rule;

class StoreMembershipRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Scoped exists rules prevent cross-gym UUID injection before services run.
            'member_id' => ['required', 'uuid', $this->tenantExists('members')],
            'plan_id' => ['required', 'uuid', $this->tenantExists('membership_plans')],
            'branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            'status' => ['sometimes', Rule::in([
                MembershipStatus::Pending->value,
                MembershipStatus::Active->value,
            ])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'auto_renew' => ['sometimes', 'boolean'],
        ];
    }
}
