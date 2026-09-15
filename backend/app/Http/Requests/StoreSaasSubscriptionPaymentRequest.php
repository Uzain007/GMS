<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

class StoreSaasSubscriptionPaymentRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'saas_plan_price_id' => ['nullable', 'required_without:saas_billing_invoice_id', 'uuid', 'exists:saas_plan_prices,id'],
            // Invoice IDs are tenant-scoped by middleware, the model concern and
            // PostgreSQL RLS before the billing service accepts a renewal.
            'saas_billing_invoice_id' => ['nullable', 'required_without:saas_plan_price_id', 'uuid', 'exists:saas_billing_invoices,id'],
            'method' => ['required', Rule::in([
                PaymentMethod::Cash->value,
                PaymentMethod::BankTransfer->value,
            ])],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120'],
            'reference' => ['required', 'string', 'min:2', 'max:160'],
            'payment_date' => [
                'required',
                'date',
                'before_or_equal:today',
            ],
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
            'payment_date.required' => $this->input('method') === PaymentMethod::Cash->value
                ? 'The payment date is required for a cash payment.'
                : 'The transfer date is required for a bank transfer.',
        ];
    }
}
