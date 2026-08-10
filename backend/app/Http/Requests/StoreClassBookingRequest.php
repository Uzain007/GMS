<?php

namespace App\Http\Requests;

class StoreClassBookingRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Staff supply a tenant member; member-role requests resolve self
            // server-side and cannot use this field to access somebody else.
            'member_id' => ['nullable', 'uuid', $this->tenantExists('members')],
        ];
    }
}
