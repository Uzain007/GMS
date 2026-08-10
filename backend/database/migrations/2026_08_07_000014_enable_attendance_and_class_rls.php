<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /** @var list<string> */
    private array $tenantTables = [
        'member_access_credentials',
        'attendance_records',
        'class_sessions',
        'class_bookings',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            // FORCE RLS keeps presence, rosters and waitlists fail-closed even
            // for raw SQL or a table-owning application connection.
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared(<<<SQL
                CREATE POLICY ironcore_tenant_isolation ON {$table}
                USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
                WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse($this->tenantTables) as $table) {
            DB::unprepared("DROP POLICY IF EXISTS ironcore_tenant_isolation ON {$table}");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
