<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'uuid', $this->tenantExists('members')],
            'membership_id' => ['nullable', 'uuid', $this->tenantExists('memberships')],
            'invoice_id' => ['nullable', 'uuid', $this->tenantExists('invoices')],
            'branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
