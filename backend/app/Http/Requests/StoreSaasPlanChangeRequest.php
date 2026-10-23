<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreSaasPlanChangeRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'saas_plan_price_id' => ['required', 'uuid', Rule::exists('saas_plan_prices', 'id')],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
