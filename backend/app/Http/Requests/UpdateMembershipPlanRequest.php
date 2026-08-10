<?php

namespace App\Http\Requests;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\PlanStatus;
use Illuminate\Validation\Rule;

class UpdateMembershipPlanRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => [
                'sometimes', 'alpha_dash:ascii', 'max:50',
                $this->tenantUnique('membership_plans', 'code')->ignore((string) $this->route('plan')),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'billing_interval' => ['sometimes', Rule::enum(BillingInterval::class)],
            'interval_count' => ['sometimes', 'integer', 'between:1,52'],
            'price_amount_minor' => ['sometimes', 'integer', 'min:0', 'max:9000000000000000'],
            'currency' => ['sometimes', Rule::enum(Currency::class)],
            'joining_fee_minor' => ['sometimes', 'integer', 'min:0', 'max:9000000000000000'],
            'duration_days' => ['nullable', 'integer', 'between:1,3650'],
            'trial_days' => ['sometimes', 'integer', 'between:0,365'],
            'status' => ['sometimes', Rule::enum(PlanStatus::class)],
            'terms' => ['nullable', 'array'],
            // Existing memberships retain snapshots; this reason explains plan edits.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
