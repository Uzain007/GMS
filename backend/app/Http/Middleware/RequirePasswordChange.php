<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // A temporary credential may establish only the narrow authenticated
        // password-change session. Tenant and platform data stay inaccessible.
        if ($user instanceof User && $user->must_change_password) {
            return new JsonResponse([
                'message' => 'You must create a new password before opening your workspace.',
                'code' => 'password_change_required',
            ], Response::HTTP_LOCKED);
        }

        return $next($request);
    }
}
