<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The partial unique index closes concurrent reissue races while
        // retaining accepted, expired and revoked evidence for the tenant.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX member_account_invitations_one_pending_member
            ON member_account_invitations (gym_id, member_id) WHERE status = 'pending'
        SQL);
        DB::unprepared('ALTER TABLE member_account_invitations ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE member_account_invitations FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_tenant_isolation ON member_account_invitations
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

        DB::unprepared('DROP INDEX IF EXISTS member_account_invitations_one_pending_member');
        DB::unprepared('DROP POLICY IF EXISTS ironcore_tenant_isolation ON member_account_invitations');
        DB::unprepared('ALTER TABLE member_account_invitations DISABLE ROW LEVEL SECURITY');
    }
};
