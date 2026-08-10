<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'member_id' => $this->member_id,
            'plan_id' => $this->plan_id,
            'branch_id' => $this->branch_id,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'next_billing_at' => $this->next_billing_at?->toDateString(),
            // These values are immutable snapshots of the accepted plan contract.
            'price_amount_minor' => $this->price_amount_minor,
            'currency' => $this->currency->value,
            'joining_fee_minor' => $this->joining_fee_minor,
            'billing_interval' => $this->billing_interval->value,
            'interval_count' => $this->interval_count,
            'auto_renew' => $this->auto_renew,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'terms_snapshot' => $this->terms_snapshot,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
