<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $role = $user->isSuperAdmin()
            ? UserRole::SuperAdmin
            : ($this->context->hasTenant() ? $user->roleForGym($this->context->id()) : null);

        if (! $role || ! in_array($role->value, $roles, true)) {
            return new JsonResponse(['message' => 'Your role cannot perform this action.'], 403);
        }

        return $next($request);
    }
}
