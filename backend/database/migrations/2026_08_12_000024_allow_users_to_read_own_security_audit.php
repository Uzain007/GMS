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

        // Platform security events have no gym_id. Let an authenticated actor
        // verify only their own whitelisted MFA history while every tenant and
        // other platform audit row remains hidden by FORCE RLS.
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_own_security_audit_select
            ON audit_logs FOR SELECT
            USING (
                gym_id IS NULL
                AND actor_id = nullif(current_setting('ironcore.current_user_id', true), '')::uuid
                AND auditable_type = 'App\Models\User'
                AND auditable_id = actor_id
                AND event IN (
                    'account.mfa.enabled',
                    'account.mfa.recovery_codes_regenerated',
                    'account.mfa.disabled'
                )
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP POLICY IF EXISTS ironcore_own_security_audit_select ON audit_logs');
        }
    }
};
