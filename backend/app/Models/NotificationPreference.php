<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['member_id', 'email_enabled', 'sms_enabled', 'push_enabled', 'class_reminders_enabled', 'workout_reminders_enabled', 'payment_reminders_enabled', 'marketing_enabled', 'quiet_hours_start', 'quiet_hours_end', 'timezone'];

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'sms_enabled' => 'boolean', 'push_enabled' => 'boolean', 'class_reminders_enabled' => 'boolean', 'workout_reminders_enabled' => 'boolean', 'payment_reminders_enabled' => 'boolean', 'marketing_enabled' => 'boolean'];
    }

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
}
