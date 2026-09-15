<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->isSuperAdmin()) {
            return $next($request);
        }

        // Database identity is already bound by the outer middleware. Rebinding
        // here would clear that request-scoped identity before tenant discovery.
        $roles = $user->gyms()->wherePivot('status', 'active')->pluck('gym_user.role');
        $maximumSeconds = $roles->contains(UserRole::Member->value) ? 6 * 60 * 60 : 10 * 60 * 60;

        $token = $user->currentAccessToken();
        $tokenCreatedAt = $token instanceof \Illuminate\Database\Eloquent\Model
            ? $token->getAttribute('created_at') : null;
        $startedAt = $tokenCreatedAt instanceof \DateTimeInterface
            ? $tokenCreatedAt->getTimestamp()
            : ($request->hasSession() && ! $token instanceof \Illuminate\Database\Eloquent\Model
                ? $request->session()->get(User::SESSION_STARTED_AT_KEY) : null);

        // Existing sessions receive a start marker on their first request after
        // rollout; new sessions are marked at login. No client value is trusted.
        if (! is_int($startedAt)) {
            if ($request->hasSession() && ! $token instanceof \Illuminate\Database\Eloquent\Model) {
                $request->session()->put(User::SESSION_STARTED_AT_KEY, now()->getTimestamp());
            }
            return $next($request);
        }

        if (now()->getTimestamp() - $startedAt < $maximumSeconds) {
            return $next($request);
        }

        if ($token instanceof \Illuminate\Database\Eloquent\Model) {
            $token->delete();
        } elseif ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        Auth::guard('sanctum')->forgetUser();
        Auth::shouldUse('web');

        return response()->json([
            'message' => 'Your session expired. Please sign in again.',
            'code' => 'session_expired',
        ], 401);
    }
}
