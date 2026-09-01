<?php

namespace App\Http\Requests;

class UpdateBankTransferSettingRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'account_name' => ['nullable', 'required_if:enabled,true', 'string', 'max:180'],
            'bank_name' => ['nullable', 'required_if:enabled,true', 'string', 'max:180'],
            'account_number_or_iban' => ['nullable', 'required_if:enabled,true', 'string', 'max:180'],
            'routing_details' => ['nullable', 'string', 'max:180'],
            'payment_instructions' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
