<?php

namespace App\Http\Requests;

class ReplaceSaasInvoiceRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'saas_plan_price_id' => ['required', 'uuid', 'exists:saas_plan_prices,id'],
            'period_start' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
