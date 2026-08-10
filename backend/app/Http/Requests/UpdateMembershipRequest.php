<?php

namespace App\Http\Requests;

use App\Enums\MembershipStatus;
use Illuminate\Validation\Rule;

class UpdateMembershipRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(MembershipStatus::class)],
            'ends_at' => ['nullable', 'date'],
            'next_billing_at' => ['nullable', 'date'],
            'auto_renew' => ['sometimes', 'boolean'],
            'cancellation_reason' => ['nullable', 'required_if:status,cancelled', 'string', 'max:1000'],
            // Lifecycle mutations are auditable even when no money changes.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
