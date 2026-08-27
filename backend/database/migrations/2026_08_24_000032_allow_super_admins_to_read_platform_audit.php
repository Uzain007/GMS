<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Platform audit rows have no gym_id. Only the request-bound identity
        // of a real super admin may read them; tenant audit rows continue to
        // require the selected gym through the original tenant policy.
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_platform_audit_select
            ON audit_logs FOR SELECT
            USING (
                gym_id IS NULL
                AND EXISTS (
                    SELECT 1 FROM users
                    WHERE users.id = nullif(current_setting('ironcore.current_user_id', true), '')::uuid
                    AND users.platform_role = 'super_admin'
                )
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP POLICY IF EXISTS ironcore_platform_audit_select ON audit_logs');
        }
    }
};
