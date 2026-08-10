<?php

namespace App\Http\Requests;

class StartSaasCheckoutRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'saas_plan_price_id' => ['required', 'uuid', 'exists:saas_plan_prices,id'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:120'],
        ];
    }
}
