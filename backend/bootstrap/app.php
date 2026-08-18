<?php

use App\Http\Middleware\BindDatabaseIdentity;
use App\Http\Middleware\EnsureAuthenticationVersion;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'database.identity' => BindDatabaseIdentity::class,
            'auth.version' => EnsureAuthenticationVersion::class,
            'tenant' => ResolveTenant::class,
            'role' => RequireRole::class,
        ]);

        // Tenant-owned route models must not be resolved until authentication,
        // PostgreSQL identity, tenant membership, and role checks are complete.
        // SQLite cannot expose this ordering defect because it has no RLS.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAuthenticationVersion::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, BindDatabaseIdentity::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
