<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount_minor' => $this->unit_amount_minor,
            'subtotal_amount_minor' => $this->subtotal_amount_minor,
            'tax_amount_minor' => $this->tax_amount_minor,
            'total_amount_minor' => $this->total_amount_minor,
            'metadata' => $this->metadata,
        ];
    }
}
