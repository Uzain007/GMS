<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BindDatabaseIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $next($request);
        }

        // The user setting lets PostgreSQL expose only the caller's own gym_user
        // rows before a tenant is selected; tenant-owned writes still need gym RLS.
        DB::statement(
            "select set_config('ironcore.current_user_id', ?, false)",
            [(string) $request->user()->getKey()]
        );

        try {
            return $next($request);
        } finally {
            // Clear pooled connections so a later request cannot inherit identity.
            DB::statement("select set_config('ironcore.current_user_id', '', false)");
        }
    }
}
