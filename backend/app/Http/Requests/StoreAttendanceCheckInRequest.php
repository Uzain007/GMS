<?php

namespace App\Http\Requests;

class StoreAttendanceCheckInRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // A missing branch is accepted only so the service can resolve the
            // tenant's single active primary location. Multiple-location gyms
            // must still make an explicit branch choice.
            'branch_id' => ['nullable', 'uuid', $this->tenantExists('gym_branches')],
            // Exactly one tenant-scoped identity path is accepted per scan.
            'credential' => ['required_without_all:member_id,member_code', 'string', 'max:512', 'prohibits:member_id,member_code'],
            'member_id' => ['required_without_all:credential,member_code', 'uuid', $this->tenantExists('members'), 'prohibits:credential,member_code'],
            'member_code' => ['required_without_all:credential,member_id', 'string', 'regex:/^\\d{4,6}$/', 'prohibits:credential,member_id'],
        ];
    }
}
