<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

class CorrectSaasSubscriptionPaymentRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:160'],
            'method' => ['nullable', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])],
            'payment_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->tenantToday()],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array', 'max:25'],
            'metadata.*' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
