<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

class StoreMemberPaymentRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Member, membership, amount and currency are resolved from this
            // tenant invoice in the controller; the browser cannot widen scope.
            'invoice_id' => ['required', 'uuid', $this->tenantExists('invoices')],
            'method' => ['required', Rule::in([
                PaymentMethod::BankTransfer->value,
                PaymentMethod::OnlineCard->value,
            ])],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'bank_reference' => ['nullable', 'string', 'max:160'],
            'receipt' => [
                'required_if:method,'.PaymentMethod::BankTransfer->value,
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
                'max:10240',
            ],
        ];
    }
}
