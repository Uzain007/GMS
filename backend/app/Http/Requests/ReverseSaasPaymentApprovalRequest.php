<?php

namespace App\Http\Requests;

class ReverseSaasPaymentApprovalRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
