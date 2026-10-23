<?php

namespace App\Http\Resources;

use App\Support\AuditPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $friendly = AuditPresentation::for(
            $this->event,
            $this->actor?->name,
            $this->gym?->name,
            $this->before_values,
            $this->after_values,
        );
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name, 'email' => $this->actor->email] : null,
            'role' => $this->actor_role,
            'gym' => $this->gym ? ['id' => $this->gym->id, 'name' => $this->gym->name] : null,
            'action' => $this->event,
            'friendly_title' => $friendly['title'],
            'friendly_description' => $friendly['description'],
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
