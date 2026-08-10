<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'member_id' => $this->member_id,
            'membership_id' => $this->membership_id,
            'invoice_id' => $this->invoice_id,
            'branch_id' => $this->branch_id,
            'receipt_number' => $this->receipt_number,
            'provider' => $this->provider->value,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'refunded_amount_minor' => $this->refunded_amount_minor,
            'currency' => $this->currency->value,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failure_message' => $this->failure_message,
            'notes' => $this->notes,
            // Provider IDs are operational references, never secret keys or card data.
            'provider_checkout_id' => $this->provider_checkout_id,
            'refunds' => PaymentRefundResource::collection($this->whenLoaded('refunds')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
