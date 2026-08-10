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
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'due_at' => $this->due_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
