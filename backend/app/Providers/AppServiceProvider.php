<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        RateLimiter::for('recovery', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        RateLimiter::for('mfa-challenge', fn (Request $request) => [
            // The opaque challenge never appears in limiter storage or logs.
            Limit::perMinute(10)->by(hash('sha256', (string) $request->input('challenge_token')).'|'.$request->ip()),
        ]);

        RateLimiter::for('mfa-management', fn (Request $request) => [
            Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier().'|'.$request->ip()),
        ]);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            $email = rawurlencode((string) $notifiable->getEmailForPasswordReset());

            // Fragments do not reach web servers or referrer headers. The web
            // client copies these values to memory and removes them at once.
            return $frontend.'/#reset_email='.$email.'&reset_token='.rawurlencode($token);
        });

        RateLimiter::for('reports', fn (Request $request) => [
            // Report pressure is isolated by authenticated operator and route
            // tenant, so one gym cannot consume another gym's allowance.
            Limit::perMinute(30)->by(implode('|', [
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
                (string) $request->route('gym'),
            ])),
        ]);

        RateLimiter::for('health', fn (Request $request) => [
            Limit::perMinute(60)->by($request->ip()),
        ]);

        RateLimiter::for('member-activation', function (Request $request): array {
            $routeGym = $request->route('gym');
            $gymId = $routeGym instanceof \App\Models\Gym
                ? (string) $routeGym->getKey()
                : (string) $routeGym;

            // Public activation is bounded by both the claimed tenant and IP;
            // invalid secrets receive the same response as expired ones.
            return [Limit::perMinute(10)->by(mb_strtolower($gymId).'|'.$request->ip())];
        });
    }
}
