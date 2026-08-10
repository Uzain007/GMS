<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
    }
}
