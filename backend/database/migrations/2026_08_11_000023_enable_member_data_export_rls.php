<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // SQLite powers the fast application suite; PostgreSQL remains the
        // authoritative runtime where forced tenant RLS is installed.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Exports contain a dense copy of member data, so forced RLS is mandatory.
        DB::unprepared('ALTER TABLE member_data_exports ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE member_data_exports FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY member_data_exports_tenant_isolation ON member_data_exports
            USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
            WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP POLICY IF EXISTS member_data_exports_tenant_isolation ON member_data_exports');
        DB::unprepared('ALTER TABLE member_data_exports DISABLE ROW LEVEL SECURITY');
    }
};
