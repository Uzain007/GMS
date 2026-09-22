<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaasBillingInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'saas_plan_price_id' => $this->subscription?->saas_plan_price_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency->value,
            'amount_due_minor' => $this->amount_due_minor,
            'amount_paid_minor' => $this->amount_paid_minor,
            'amount_remaining_minor' => $this->amount_remaining_minor,
            // These are short-lived/unguessable provider-hosted documents and
            // this resource is restricted to billing-authorised tenant roles.
            'hosted_invoice_url' => $this->hosted_invoice_url,
            'invoice_pdf_url' => $this->invoice_pdf_url,
            'available_at' => $this->available_at,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'due_at' => $this->due_at,
            'grace_ends_at' => $this->grace_ends_at,
            'paid_at' => $this->paid_at,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'correction_history' => $this->whenLoaded('auditLogs', fn () => $this->auditLogs->map(fn ($entry): array => [
                'id' => $entry->id,
                'action' => $entry->event,
                'old_value' => $entry->before_values,
                'new_value' => $entry->after_values,
                'reason' => $entry->reason,
                'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name] : null,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
