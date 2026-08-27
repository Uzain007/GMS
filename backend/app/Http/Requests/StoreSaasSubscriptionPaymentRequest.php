<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

class StoreSaasSubscriptionPaymentRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'saas_plan_price_id' => ['required', 'uuid', 'exists:saas_plan_prices,id'],
            'method' => ['required', Rule::in([
                PaymentMethod::Cash->value,
                PaymentMethod::BankTransfer->value,
            ])],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120'],
            'reference' => ['required', 'string', 'min:2', 'max:160'],
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
