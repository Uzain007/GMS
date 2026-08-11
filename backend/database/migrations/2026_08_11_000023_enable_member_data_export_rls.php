<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Exports contain a dense copy of member data, so forced RLS is mandatory.
        DB::unprepared('ALTER TABLE member_data_exports ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE member_data_exports FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY member_data_exports_tenant_isolation ON member_data_exports
            USING (gym_id = current_setting('app.current_gym_id', true)::uuid)
            WITH CHECK (gym_id = current_setting('app.current_gym_id', true)::uuid)
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP POLICY IF EXISTS member_data_exports_tenant_isolation ON member_data_exports');
        DB::unprepared('ALTER TABLE member_data_exports DISABLE ROW LEVEL SECURITY');
    }
};
