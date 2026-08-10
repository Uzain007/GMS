<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'gym_id' => $this->gym_id, 'member_id' => $this->member_id,
            'channel' => $this->channel->value, 'template_key' => $this->template_key,
            'status' => $this->status->value, 'attempts' => $this->attempts,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(), 'sent_at' => $this->sent_at?->toIso8601String(),
            'failure_code' => $this->failure_code,
            // Encrypted destination and provider response details are never returned.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
