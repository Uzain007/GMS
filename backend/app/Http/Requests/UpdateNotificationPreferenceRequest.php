<?php

namespace App\Http\Requests;

use DateTimeZone;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferenceRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'email_enabled' => ['sometimes', 'boolean'],
            'sms_enabled' => ['sometimes', 'boolean'],
            'push_enabled' => ['sometimes', 'boolean'],
            'class_reminders_enabled' => ['sometimes', 'boolean'],
            'workout_reminders_enabled' => ['sometimes', 'boolean'],
            'payment_reminders_enabled' => ['sometimes', 'boolean'],
            'marketing_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ];
    }
}
