<?php

namespace App\Http\Middleware;

use App\Enums\GymStatus;
use App\Enums\UserRole;
use App\Enums\SaasSubscriptionStatus;
use App\Models\GymSubscription;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTenantBillingAccess
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->isSuperAdmin()) {
            return $next($request);
        }

        // Terminal history may be newer than the accepted contract. Selecting
        // only a current subscription keeps access decisions on one ledger row.
        $gym = $this->tenant->gym();
        $subscription = GymSubscription::query()
            ->where('status', SaasSubscriptionStatus::Active->value)->latest()->first()
            ?? GymSubscription::query()->current()->latest()->first();
        $overrideActive = $subscription?->billing_override_until?->isFuture() ?? false;
        $trialEndsAt = $subscription?->trial_ends_at ?? $gym->trial_ends_at;
        $trialExpired = $subscription?->status !== SaasSubscriptionStatus::Active
            && $trialEndsAt?->isPast() === true;
        $legacyPastDue = $subscription === null && $gym->status === GymStatus::PastDue;
        $restricted = $trialExpired || $legacyPastDue || ($subscription !== null
            && $subscription->billing_restricted_at !== null && ! $overrideActive);
        if (! $restricted) {
            return $next($request);
        }

        $role = $request->user()->roleForGym($this->tenant->id());
        $path = '/'.$request->path();
        $billingPath = preg_match('#^/api/v1/gyms/[^/]+/(saas-|saas_)#', $path) === 1
            || preg_match('#^/api/v1/gyms/[^/]+/saas#', $path) === 1;
        $gymSummaryPath = preg_match('#^/api/v1/gyms/[^/]+$#', $path) === 1 && $request->isMethod('GET');

        // A restricted owner/manager can still understand and settle billing.
        // Reception, trainer and member operations fail closed until settlement.
        if (in_array($role, [UserRole::GymOwner, UserRole::GymManager], true)
            && ($billingPath || $gymSummaryPath)) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => $trialExpired
                ? 'This gym trial has ended. A Gym Owner can open SaaS billing, select a plan and complete payment.'
                : 'This gym is in billing-restricted mode. A Gym Owner can open SaaS billing to resolve the overdue invoice.',
            'code' => 'saas_billing_restricted',
            'reason' => $trialExpired ? 'trial_expired' : 'payment_required',
        ], 402);
    }
}
