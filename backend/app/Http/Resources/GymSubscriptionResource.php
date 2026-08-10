<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GymSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'status' => $this->status->value,
            'plan_code' => $this->plan_code_snapshot,
            'plan_name' => $this->plan_name_snapshot,
            'feature_limits' => $this->feature_limits_snapshot,
            'currency' => $this->currency->value,
            'amount_minor' => $this->amount_minor,
            'billing_interval' => $this->billing_interval,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'trial_ends_at' => $this->trial_ends_at,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'cancelled_at' => $this->cancelled_at,
            'ended_at' => $this->ended_at,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'billing_contact' => $this->whenLoaded('customer', fn (): array => [
                'email' => $this->customer->billing_email,
                'name' => $this->customer->billing_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
