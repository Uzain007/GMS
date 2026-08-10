<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /** @var list<string> */
    private array $tenantTables = [
        'audit_logs',
        'gym_branches',
        'members',
        'staff_profiles',
        'staff_profile_branch',
        'staff_invitations',
        'membership_plans',
        'memberships',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Partial unique indexes close concurrency races without bloating other
        // lifecycle states or relying only on application-level pre-checks.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX gym_branches_one_primary_per_gym
            ON gym_branches (gym_id) WHERE is_primary = true
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX memberships_one_current_per_member
            ON memberships (gym_id, member_id) WHERE status IN ('pending', 'active')
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX staff_invitations_one_pending_email
            ON staff_invitations (gym_id, email) WHERE status = 'pending'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX staff_invitations_one_pending_employee
            ON staff_invitations (gym_id, employee_number) WHERE status = 'pending'
        SQL);

        foreach ($this->tenantTables as $table) {
            // Static table names plus FORCE RLS protect data even when the app's
            // database role owns the table and a query omits the Eloquent scope.
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared(<<<SQL
                CREATE POLICY ironcore_tenant_isolation ON {$table}
                USING (
                    gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid
                )
                WITH CHECK (
                    gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid
                )
            SQL);
        }

        // A signed-in user may discover only their own active gym assignments
        // before choosing a tenant; all pivot writes still require gym context.
        DB::unprepared('ALTER TABLE gym_user ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE gym_user FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_gym_user_isolation ON gym_user
            USING (
                gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid
                OR user_id = nullif(current_setting('ironcore.current_user_id', true), '')::uuid
            )
            WITH CHECK (
                gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP INDEX IF EXISTS staff_invitations_one_pending_employee');
        DB::unprepared('DROP INDEX IF EXISTS staff_invitations_one_pending_email');
        DB::unprepared('DROP INDEX IF EXISTS memberships_one_current_per_member');
        DB::unprepared('DROP INDEX IF EXISTS gym_branches_one_primary_per_gym');

        DB::unprepared('DROP POLICY IF EXISTS ironcore_gym_user_isolation ON gym_user');
        DB::unprepared('ALTER TABLE gym_user DISABLE ROW LEVEL SECURITY');

        foreach (array_reverse($this->tenantTables) as $table) {
            DB::unprepared("DROP POLICY IF EXISTS ironcore_tenant_isolation ON {$table}");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
