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
            'refunded_amount_minor' => $this->refunded_amount_minor,
            'currency' => $this->currency->value,
            'reference' => $this->reference,
            'effective_reference' => $this->relationLoaded('corrections') ? ($this->corrections->reverse()->first(fn ($item) => $item->reference !== null)?->reference ?? $this->reference) : $this->reference,
            'effective_method' => $this->relationLoaded('corrections') ? ($this->corrections->reverse()->first(fn ($item) => $item->method !== null)?->method?->value ?? $this->method->value) : $this->method->value,
            'payment_date' => $this->payment_date?->toDateString(),
            'effective_payment_date' => $this->relationLoaded('corrections') ? ($this->corrections->reverse()->first(fn ($item) => $item->payment_date !== null)?->payment_date?->toDateString() ?? $this->payment_date?->toDateString()) : $this->payment_date?->toDateString(),
            'effective_amount_minor' => $this->relationLoaded('corrections') ? ($this->corrections->reverse()->first(fn ($item) => $item->amount_minor !== null)?->amount_minor ?? $this->amount_minor) : $this->amount_minor,
            'corrections' => $this->whenLoaded('corrections', fn () => $this->corrections->map(fn ($correction): array => [
                'id' => $correction->id,
                'reference' => $correction->reference,
                'method' => $correction->method?->value,
                'payment_date' => $correction->payment_date?->toDateString(),
                'amount_minor' => $correction->amount_minor,
                'internal_notes' => $correction->internal_notes,
                'metadata' => $correction->metadata,
                'reason' => $correction->reason,
                'corrected_by' => $correction->correctedBy ? ['id' => $correction->correctedBy->id, 'name' => $correction->correctedBy->name] : null,
                'created_at' => $correction->created_at?->toIso8601String(),
            ])->values()),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn ($refund): array => [
                'id' => $refund->id,
                'status' => $refund->status->value,
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency->value,
                'reason' => $refund->reason,
                'recorded_by' => $refund->recordedBy ? ['id' => $refund->recordedBy->id, 'name' => $refund->recordedBy->name] : null,
                'refunded_at' => $refund->refunded_at?->toIso8601String(),
            ])->values()),
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
