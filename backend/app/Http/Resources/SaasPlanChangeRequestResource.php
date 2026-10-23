<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaasPlanChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id,
            'gym' => $this->whenLoaded('gym', fn () => ['id' => $this->gym->id, 'name' => $this->gym->name]),
            'subscription_id' => $this->gym_subscription_id,
            'current_plan' => ['id' => $this->currentPlan?->id, 'name' => $this->currentPlan?->name],
            'current_price' => ['id' => $this->currentPrice?->id, 'amount_minor' => $this->currentPrice?->amount_minor, 'currency' => $this->currentPrice?->currency?->value, 'billing_interval' => $this->currentPrice?->billing_interval],
            'requested_plan' => ['id' => $this->requestedPlan?->id, 'name' => $this->requestedPlan?->name],
            'requested_price' => ['id' => $this->requestedPrice?->id, 'amount_minor' => $this->requestedPrice?->amount_minor, 'currency' => $this->requestedPrice?->currency?->value, 'billing_interval' => $this->requestedPrice?->billing_interval],
            'change_type' => $this->change_type, 'status' => $this->status->value,
            'reason' => $this->reason, 'review_reason' => $this->review_reason,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name, 'email' => $this->requestedBy->email]),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy ? ['id' => $this->reviewedBy->id, 'name' => $this->reviewedBy->name] : null),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
