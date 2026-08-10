<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'billing_interval' => $this->billing_interval->value,
            'interval_count' => $this->interval_count,
            // Clients receive exact minor units and ISO currency, never floats.
            'price_amount_minor' => $this->price_amount_minor,
            'currency' => $this->currency->value,
            'joining_fee_minor' => $this->joining_fee_minor,
            'duration_days' => $this->duration_days,
            'trial_days' => $this->trial_days,
            'status' => $this->status->value,
            'terms' => $this->terms,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
