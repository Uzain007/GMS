<?php

namespace App\Services;

use App\Enums\GymStatus;
use App\Models\Gym;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GymLifecycleService
{
    private const HISTORY_TABLES = [
        'members', 'membership_plans', 'memberships', 'attendance_records', 'class_sessions',
        'class_bookings', 'invoices', 'payments', 'payment_refunds', 'gym_subscriptions',
        'saas_billing_invoices', 'saas_subscription_payments', 'workout_plans', 'workout_sessions',
        'member_progress_measurements',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
    ) {}

    public function hardDelete(Gym $gym, string $confirmation, string $reason, Request $request): void
    {
        if ($gym->status !== GymStatus::Cancelled || ! hash_equals($gym->name, $confirmation)) {
            throw ValidationException::withMessages([
                'confirmation' => ['Archive the gym first, then enter its exact name to confirm permanent deletion.'],
            ]);
        }

        $blocked = collect(self::HISTORY_TABLES)->first(fn (string $table): bool => DB::table($table)->where('gym_id', $gym->id)->exists());
        $hasBusinessAudit = DB::table('audit_logs')->where('gym_id', $gym->id)
            ->whereNotIn('event', ['gym.created', 'gym.owner_account.created', 'gym.updated'])
            ->exists();
        if ($blocked || $hasBusinessAudit) {
            throw ValidationException::withMessages([
                'gym' => ['Permanent deletion is blocked because this gym has business, financial, attendance or audit history. Keep it archived instead.'],
            ]);
        }

        DB::transaction(function () use ($gym, $reason, $request): void {
            $this->audit->record('gym.hard_deleted', $gym, $request->user(), before: $gym->toArray(), reason: $reason, request: $request);
            $gym->delete();
        });
    }
}
