<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Concerns\BelongsToGym;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use BelongsToGym, HasUuids;

    protected $fillable = ['member_id', 'triggered_by', 'channel', 'template_key', 'destination', 'variables', 'idempotency_key', 'status', 'attempts', 'provider_message_id', 'failure_code', 'failure_message', 'scheduled_at', 'sent_at'];
    protected $hidden = ['destination'];

    protected function casts(): array
    {
        return [
            // Destination ciphertext is never exposed by API resources/logs.
            'destination' => 'encrypted', 'variables' => 'array',
            'channel' => NotificationChannel::class, 'status' => NotificationDeliveryStatus::class,
            'attempts' => 'integer', 'scheduled_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime',
        ];
    }

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function triggeredBy(): BelongsTo { return $this->belongsTo(User::class, 'triggered_by'); }
}
