<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Models\Gym;
use App\Models\Member;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresRowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_hides_rows_outside_the_connection_tenant(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise row-level security.');
        }

        $isSuperuser = (bool) DB::selectOne(
            'select rolsuper from pg_roles where rolname = current_user'
        )->rolsuper;
        if ($isSuperuser) {
            $this->markTestSkipped('RLS must be tested with the non-superuser ironcore_app role.');
        }

        $gymA = Gym::factory()->create();
        $gymB = Gym::factory()->create();
        $context = app(TenantContext::class);

        $context->run($gymA, fn () => Member::query()->create([
            'member_number' => 'RLS-A', 'first_name' => 'A', 'last_name' => 'Member',
            'status' => MemberStatus::Active,
        ]));
        $memberB = $context->run($gymB, fn () => Member::query()->create([
            'member_number' => 'RLS-B', 'first_name' => 'B', 'last_name' => 'Member',
            'status' => MemberStatus::Active,
        ]));

        $context->set($gymA);
        try {
            // Raw SQL deliberately bypasses Eloquent; PostgreSQL must still hide B.
            $this->assertSame(0, DB::table('members')->where('id', $memberB->id)->count());
        } finally {
            $context->clear();
        }
    }
}
