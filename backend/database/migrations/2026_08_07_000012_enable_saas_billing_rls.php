<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /** @var list<string> */
    private array $tenantTables = [
        'platform_billing_customers',
        'gym_subscriptions',
        'subscription_checkout_sessions',
        'saas_billing_invoices',
        'saas_billing_webhook_events',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            // FORCE RLS keeps subscription and invoice records fail-closed even
            // if a later query bypasses Eloquent or the application owns tables.
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared(<<<SQL
                CREATE POLICY ironcore_tenant_isolation ON {$table}
                USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
                WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
            SQL);
        }

        // A separate Stripe Billing secret must be verified before this setting
        // is populated. The policy exposes exactly one opaque customer mapping.
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_billing_webhook_customer_lookup
            ON platform_billing_customers FOR SELECT
            USING (
                provider_customer_id = nullif(
                    current_setting('ironcore.current_billing_customer_id', true),
                    ''
                )
            )
        SQL);

        // Platform catalogue mutations are auditable without inventing a gym.
        // The actor must be the request-bound identity and a real super admin.
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_platform_audit_insert
            ON audit_logs FOR INSERT
            WITH CHECK (
                gym_id IS NULL
                AND actor_id = nullif(current_setting('ironcore.current_user_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM users
                    WHERE users.id = actor_id AND users.platform_role = 'super_admin'
                )
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP POLICY IF EXISTS ironcore_platform_audit_insert ON audit_logs');
        DB::unprepared('DROP POLICY IF EXISTS ironcore_billing_webhook_customer_lookup ON platform_billing_customers');
        foreach (array_reverse($this->tenantTables) as $table) {
            DB::unprepared("DROP POLICY IF EXISTS ironcore_tenant_isolation ON {$table}");
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
