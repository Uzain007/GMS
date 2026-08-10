<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    public function __construct(private readonly TenantContext $context) {}

    public function record(
        string $event,
        ?Model $subject,
        ?User $actor,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'gym_id' => $this->context->hasTenant()
                ? $this->context->id()
                : ($subject instanceof Gym ? $subject->getKey() : null),
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'before_values' => $before ?: null,
            'after_values' => $after ?: null,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
