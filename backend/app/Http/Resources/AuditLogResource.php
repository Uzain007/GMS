<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name, 'email' => $this->actor->email] : null,
            'role' => $this->actor_role,
            'gym' => $this->gym ? ['id' => $this->gym->id, 'name' => $this->gym->name] : null,
            'action' => $this->event,
            'resource' => $this->auditable_type,
            'resource_id' => $this->auditable_id,
            'old_value' => $this->before_values,
            'new_value' => $this->after_values,
            'reason' => $this->reason,
            'ip_address' => $this->ip_address,
            'device' => $this->user_agent,
        ];
    }
}
