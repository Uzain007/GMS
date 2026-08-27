<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class DatabaseIdentityContext
{
    public function run(User $user, Closure $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return $callback();
        }

        // PostgreSQL may reveal only this identity's gym assignments before a
        // tenant is selected. Clearing the setting prevents pooled-request leaks.
        DB::statement(
            "select set_config('ironcore.current_user_id', ?, false)",
            [(string) $user->getKey()]
        );

        try {
            return $callback();
        } finally {
            DB::statement("select set_config('ironcore.current_user_id', '', false)");
        }
    }
}
