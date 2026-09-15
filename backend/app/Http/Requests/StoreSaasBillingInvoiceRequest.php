<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreSaasBillingInvoiceRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'saas_plan_price_id' => ['required', 'uuid', 'exists:saas_plan_prices,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(['GBP', 'USD', 'PKR', 'AED', 'SAR'])],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
