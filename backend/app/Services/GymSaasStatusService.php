<?php

namespace App\Services;

use App\Enums\GymStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Models\Gym;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GymSaasStatusService
{
    public function __construct(private readonly AuditService $audit) {}

    public function synchronize(
        Gym $gym,
        SaasSubscriptionStatus $subscriptionStatus,
        ?Carbon $trialEndsAt = null,
        ?User $actor = null,
        ?string $reason = null,
        ?Request $request = null,
    ): Gym {
        // Lock the platform-owned tenant registry row so a provider event cannot
        // race and overwrite a Super Admin lifecycle override.
        $locked = Gym::query()->lockForUpdate()->findOrFail($gym->getKey());
        $targetStatus = $this->gymStatus($subscriptionStatus);

        // Suspended and cancelled are explicit platform overrides. Billing can
        // keep its own ledger current, but only a Super Admin may reopen access.
        if (in_array($locked->status, [GymStatus::Suspended, GymStatus::Cancelled], true)) {
            return $locked;
        }

        $targetTrialEndsAt = $subscriptionStatus === SaasSubscriptionStatus::Trialing
            ? $trialEndsAt
            : null;
        $trialChanged = $locked->trial_ends_at?->getTimestamp() !== $targetTrialEndsAt?->getTimestamp();
        if ($locked->status === $targetStatus && ! $trialChanged) {
            return $locked;
        }

        $before = [
            'status' => $locked->status->value,
            'trial_ends_at' => $locked->trial_ends_at?->toIso8601String(),
        ];
        $locked->update([
            'status' => $targetStatus,
            'trial_ends_at' => $targetTrialEndsAt,
        ]);
        $fresh = $locked->fresh();

        $this->audit->record(
            'gym.saas_status.synchronized',
            $fresh,
            $actor,
            before: $before,
            after: [
                'status' => $fresh->status->value,
                'trial_ends_at' => $fresh->trial_ends_at?->toIso8601String(),
                'subscription_status' => $subscriptionStatus->value,
            ],
            reason: $reason ?? 'SaaS billing lifecycle synchronization.',
            request: $request,
        );

        return $fresh;
    }

    private function gymStatus(SaasSubscriptionStatus $status): GymStatus
    {
        return match ($status) {
            SaasSubscriptionStatus::Active => GymStatus::Active,
            SaasSubscriptionStatus::Trialing => GymStatus::Trial,
            SaasSubscriptionStatus::Incomplete,
            SaasSubscriptionStatus::PastDue,
            SaasSubscriptionStatus::Unpaid => GymStatus::PastDue,
            SaasSubscriptionStatus::Paused,
            SaasSubscriptionStatus::Cancelled,
            SaasSubscriptionStatus::IncompleteExpired => GymStatus::Suspended,
        };
    }
}
