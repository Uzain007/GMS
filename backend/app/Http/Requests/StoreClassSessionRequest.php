<?php

namespace App\Http\Requests;

class StoreClassSessionRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', $this->tenantExists('gym_branches')],
            'trainer_staff_profile_id' => ['nullable', 'uuid', $this->tenantExists('staff_profiles')],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            'booking_opens_at' => ['nullable', 'date', 'before:ends_at'],
            'booking_closes_at' => ['nullable', 'date', 'after:booking_opens_at', 'before_or_equal:ends_at'],
        ];
    }
}
