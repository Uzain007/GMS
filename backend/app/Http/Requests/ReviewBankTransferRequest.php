<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReviewBankTransferRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
