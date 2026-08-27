<?php

namespace App\Http\Requests;

class UpdateOwnStaffProfileRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // A trainer may update only tenant-local presentation/contact
            // fields. Role, status, branch and platform identity stay managed.
            'display_name' => ['sometimes', 'required', 'string', 'max:160'],
            'contact_email' => ['sometimes', 'required', 'email:rfc', 'max:254'],
            'phone' => ['sometimes', 'required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
