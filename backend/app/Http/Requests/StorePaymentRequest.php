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
            'payment_date' => ['nullable', 'required_if:method,'.PaymentMethod::Cash->value, 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
            'bank_reference' => ['nullable', 'string', 'max:160'],
            'transferred_on' => ['nullable', 'required_if:method,bank_transfer', 'date', 'before_or_equal:today'],
            'receipt' => [
                'required_if:method,'.PaymentMethod::BankTransfer->value,
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_date.required_if' => 'The payment date is required for a cash payment.',
            'transferred_on.required_if' => 'The transfer date is required for a bank transfer.',
        ];
    }
}
