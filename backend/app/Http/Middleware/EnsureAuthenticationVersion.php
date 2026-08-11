<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Sanctum bearer credentials are revoked explicitly. Stateful browser
        // sessions additionally carry a generation so revocation stays
        // independent of the configured Redis/database session driver.
        if ($user instanceof User && ! $user->currentAccessToken()) {
            $sessionVersion = $request->session()->get(User::SESSION_AUTH_VERSION_KEY);

            if (! is_int($sessionVersion) || $sessionVersion !== $user->auth_version) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'message' => 'This session is no longer valid. Please sign in again.',
                ], 401);
            }
        }

        return $next($request);
    }
}
