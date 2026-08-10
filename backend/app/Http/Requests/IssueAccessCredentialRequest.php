<?php

namespace App\Http\Requests;

class IssueAccessCredentialRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
