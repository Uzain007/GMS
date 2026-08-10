<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Import metadata can expose filenames and errors, so it receives the
        // same forced database boundary as member records.
        DB::unprepared('ALTER TABLE member_imports ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE member_imports FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_tenant_isolation ON member_imports
            USING (
                gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid
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

        DB::unprepared('DROP POLICY IF EXISTS ironcore_tenant_isolation ON member_imports');
        DB::unprepared('ALTER TABLE member_imports DISABLE ROW LEVEL SECURITY');
    }
};
