<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /** @var list<string> */
    private array $tenantTables = [
        'payment_gateway_accounts',
        'invoices',
        'invoice_items',
        'payments',
        'payment_refunds',
        'payment_webhook_events',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            // FORCE RLS protects financial data even if future code bypasses an
            // Eloquent scope or the application role owns the table.
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

        // The webhook service sets this opaque provider account only after HMAC
        // verification. It can resolve one gateway row, then immediately binds
        // the normal gym context before touching any financial record.
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_gateway_webhook_lookup
            ON payment_gateway_accounts FOR SELECT
            USING (
                provider_account_id = nullif(
                    current_setting('ironcore.current_provider_account_id', true),
                    ''
                )
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP POLICY IF EXISTS ironcore_gateway_webhook_lookup ON payment_gateway_accounts');
        foreach (array_reverse($this->tenantTables) as $table) {
            DB::unprepared("DROP POLICY IF EXISTS ironcore_tenant_isolation ON {$table}");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
