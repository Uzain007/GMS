<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
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

        $subscription = GymSubscription::query()->latest()->first();
        $restricted = $subscription?->billing_restricted_at !== null
            && ! ($subscription->billing_override_until?->isFuture() ?? false);
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
            'message' => 'This gym is in billing-restricted mode. A Gym Owner can open SaaS billing to resolve the overdue invoice.',
            'code' => 'saas_billing_restricted',
        ], 402);
    }
}
