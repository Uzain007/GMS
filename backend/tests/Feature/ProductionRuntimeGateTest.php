<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionRuntimeGateTest extends TestCase
{
    use RefreshDatabase;

    private function postgresBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    public function test_ci_runs_on_non_superuser_postgres_with_forced_rls_and_redis(): void
    {
        if (! filter_var(env('IRONCORE_RUNTIME_GATE', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('The production runtime assertions run only in the explicit CI gate.');
        }

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
        $this->assertSame('redis', config('queue.default'));

        $role = DB::selectOne(<<<'SQL'
            select current_user as role,
                   rolsuper,
                   rolcreatedb,
                   rolcreaterole,
                   rolinherit,
                   rolbypassrls
              from pg_roles
             where rolname = current_user
            SQL);

        $this->assertSame('ironcore_app', $role->role);
        $this->assertFalse($this->postgresBoolean($role->rolsuper));
        $this->assertFalse($this->postgresBoolean($role->rolcreatedb));
        $this->assertFalse($this->postgresBoolean($role->rolcreaterole));
        $this->assertFalse($this->postgresBoolean($role->rolinherit));
        $this->assertFalse($this->postgresBoolean($role->rolbypassrls));

        // Every public table carrying tenant identity must keep both ordinary
        // and FORCE RLS enabled; deriving the set avoids a stale hand-written list.
        $tenantTables = DB::select(<<<'SQL'
            select distinct c.relname as table_name,
                   c.relrowsecurity as rls_enabled,
                   c.relforcerowsecurity as rls_forced
              from pg_class c
              join pg_namespace n on n.oid = c.relnamespace
              join pg_attribute a on a.attrelid = c.oid
             where n.nspname = 'public'
               and c.relkind = 'r'
               and a.attname = 'gym_id'
               and not a.attisdropped
             order by c.relname
            SQL);

        $this->assertNotEmpty($tenantTables);
        foreach ($tenantTables as $table) {
            $this->assertTrue(
                $this->postgresBoolean($table->rls_enabled),
                "RLS is disabled on {$table->table_name}.",
            );
            $this->assertTrue(
                $this->postgresBoolean($table->rls_forced),
                "FORCE RLS is disabled on {$table->table_name}.",
            );
        }

        $cacheKey = 'ironcore:ci:runtime:'.Str::uuid();
        Cache::store('redis')->put($cacheKey, 'reachable', 30);

        try {
            $this->assertSame('reachable', Cache::store('redis')->get($cacheKey));
        } finally {
            Cache::store('redis')->forget($cacheKey);
        }
    }
}
