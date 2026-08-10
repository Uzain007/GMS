<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'uuid', $this->tenantExists('members')],
            'membership_id' => ['nullable', 'uuid', $this->tenantExists('memberships')],
            'branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            'currency' => ['required', Rule::enum(Currency::class)],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:240'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            // Monetary fields are already integer minor units from the client;
            // the service recomputes every total and ignores client totals.
            'items.*.unit_amount_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'items.*.tax_amount_minor' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'],
            'items.*.metadata' => ['nullable', 'array'],
        ];
    }
}
