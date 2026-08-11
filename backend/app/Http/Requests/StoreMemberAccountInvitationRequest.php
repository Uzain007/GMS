<?php

namespace App\Http\Requests;

class StoreMemberAccountInvitationRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'expires_in_hours' => ['sometimes', 'integer', 'between:1,168'],
        ];
    }
}
