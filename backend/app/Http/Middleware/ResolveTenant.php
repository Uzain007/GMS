<?php

namespace App\Http\Middleware;

use App\Models\Gym;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeGym = $request->route('gym');
        $routeGymId = $routeGym instanceof Gym ? (string) $routeGym->getKey() : trim((string) $routeGym);
        $headerGymId = trim((string) $request->header('X-Gym-ID'));

        // Require route and header agreement before loading or binding a tenant.
        // This prevents a stale/malicious client header selecting a different
        // gym than the URL displayed to the operator.
        if ($routeGymId === '' || $headerGymId === '' || ! hash_equals(
            mb_strtolower($routeGymId),
            mb_strtolower($headerGymId),
        )) {
            return new JsonResponse(['message' => 'The gym context does not match the request route.'], 422);
        }

        $gym = $routeGym instanceof Gym ? $routeGym : Gym::query()->find($routeGymId);

        if (! $gym) {
            return new JsonResponse(['message' => 'A valid gym context is required.'], 422);
        }

        // Bind the tenant before reading tenant membership so PostgreSQL RLS and
        // Eloquent scopes agree on the exact gym for the full request lifetime.
        $this->context->set($gym);
        try {
            $user = $request->user();
            $hasActiveAccess = $user->gyms()
                ->wherePivot('status', 'active')
                ->whereKey($gym->getKey())
                ->exists();

            if (! $user->isSuperAdmin() && ! $hasActiveAccess) {
                return new JsonResponse(['message' => 'You do not have access to this gym.'], 403);
            }

            // The route tenant has now been validated and promoted into the
            // trusted context. Remove the consumed parameter so Laravel's
            // positional controller dispatch cannot shift every nested route
            // parameter (member, staff, booking, export, and so on) by one.
            $request->route()->forgetParameter('gym');

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
