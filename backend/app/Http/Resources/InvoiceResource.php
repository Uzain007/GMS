<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gym_id' => $this->gym_id,
            'member_id' => $this->member_id,
            'membership_id' => $this->membership_id,
            'branch_id' => $this->branch_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency->value,
            'subtotal_amount_minor' => $this->subtotal_amount_minor,
            'tax_amount_minor' => $this->tax_amount_minor,
            'total_amount_minor' => $this->total_amount_minor,
            'paid_amount_minor' => $this->paid_amount_minor,
            'due_amount_minor' => $this->due_amount_minor,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'billing_cycle_date' => $this->billing_cycle_date?->toDateString(),
            'grace_ends_at' => $this->grace_ends_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'notes' => $this->notes,
            // Items are always loaded through their own fail-closed tenant scope.
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
