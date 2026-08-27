<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Receipt metadata is as tenant-sensitive as the private object itself.
        DB::unprepared('ALTER TABLE bank_transfer_receipts ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE bank_transfer_receipts FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_tenant_isolation ON bank_transfer_receipts
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

        DB::unprepared('DROP POLICY IF EXISTS ironcore_tenant_isolation ON bank_transfer_receipts');
        DB::unprepared('ALTER TABLE bank_transfer_receipts DISABLE ROW LEVEL SECURITY');
    }
};
