<?php

namespace App\Http\Requests;

class StoreRefundRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            // Refunds are irreversible financial actions, so a human-readable
            // reason is required for the tenant audit trail.
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
