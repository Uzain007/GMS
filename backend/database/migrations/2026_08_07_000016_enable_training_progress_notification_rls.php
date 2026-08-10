<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /** @var list<string> */
    private array $tenantTables = [
        'trainer_member_assignments',
        'workout_plans',
        'workout_plan_exercises',
        'workout_sessions',
        'workout_set_logs',
        'member_progress_measurements',
        'notification_preferences',
        'notification_deliveries',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            // FORCE RLS keeps coaching history and encrypted delivery evidence
            // isolated even through raw SQL or table-owner connections.
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
