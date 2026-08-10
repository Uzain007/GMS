<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id, 'member_id' => $this->member_id,
            'email_enabled' => $this->email_enabled, 'sms_enabled' => $this->sms_enabled,
            'push_enabled' => $this->push_enabled, 'class_reminders_enabled' => $this->class_reminders_enabled,
            'workout_reminders_enabled' => $this->workout_reminders_enabled,
            'payment_reminders_enabled' => $this->payment_reminders_enabled,
            'marketing_enabled' => $this->marketing_enabled,
            'quiet_hours_start' => $this->quiet_hours_start, 'quiet_hours_end' => $this->quiet_hours_end,
            'timezone' => $this->timezone,
        ];
    }
}
