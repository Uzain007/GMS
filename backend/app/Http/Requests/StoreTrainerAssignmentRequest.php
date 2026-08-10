<?php

namespace App\Http\Requests;

class StoreTrainerAssignmentRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'trainer_staff_profile_id' => ['required', 'uuid', $this->tenantExists('staff_profiles')],
            'member_id' => ['required', 'uuid', $this->tenantExists('members')],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
