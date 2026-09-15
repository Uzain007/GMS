<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrepareSaasSubscriptionInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'saas_plan_price_id' => ['required', 'uuid', 'exists:saas_plan_prices,id'],
            'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:160'],
        ];
    }
}
