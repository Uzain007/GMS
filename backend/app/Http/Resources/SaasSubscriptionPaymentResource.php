<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaasSubscriptionPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'saas_plan_price_id' => $this->saas_plan_price_id,
            'gym_subscription_id' => $this->gym_subscription_id,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency->value,
            'reference' => $this->reference,
            'has_receipt' => filled($this->receipt_path),
            'receipt_original_name' => $this->receipt_original_name,
            'reviewed_at' => $this->reviewed_at,
            'review_reason' => $this->review_reason,
            'paid_at' => $this->paid_at,
            'plan' => $this->whenLoaded('price', fn (): array => [
                'id' => $this->price->plan->id,
                'name' => $this->price->plan->name,
                'code' => $this->price->plan->code,
                'billing_interval' => $this->price->billing_interval,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
