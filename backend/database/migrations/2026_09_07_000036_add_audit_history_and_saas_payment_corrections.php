<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            // Role is snapshotted at event time so historical audit exports do
            // not change when an operator is later reassigned or suspended.
            $table->string('actor_role', 40)->nullable()->after('actor_id');
            $table->index(['gym_id', 'actor_role', 'created_at']);
        });

        Schema::create('saas_payment_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('saas_subscription_payment_id');
            $table->uuid('corrected_by');
            $table->string('reference', 160)->nullable();
            $table->string('method', 30)->nullable();
            $table->text('internal_notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('reason');
            $table->timestampTz('created_at')->useCurrent();

            // A correction is append-only and tenant-owned. The composite FK
            // prevents a payment from another gym being referenced by UUID.
            $table->foreign('gym_id')->references('id')->on('gyms')->restrictOnDelete();
            $table->foreign(['gym_id', 'saas_subscription_payment_id'])
                ->references(['gym_id', 'id'])->on('saas_subscription_payments')->restrictOnDelete();
            $table->foreign('corrected_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['gym_id', 'saas_subscription_payment_id', 'created_at']);
            $table->index(['gym_id', 'created_at']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // A request-bound Super Admin may inspect tenant audit rows without
        // disabling RLS. Tenant operators still require the original gym policy.
        DB::unprepared('DROP POLICY IF EXISTS ironcore_platform_audit_select ON audit_logs');
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_platform_audit_select
            ON audit_logs FOR SELECT
            USING (
                EXISTS (
                    SELECT 1 FROM users
                    WHERE users.id = nullif(current_setting('ironcore.current_user_id', true), '')::uuid
                    AND users.platform_role = 'super_admin'
                )
            )
        SQL);

        DB::unprepared('ALTER TABLE saas_payment_corrections ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE saas_payment_corrections FORCE ROW LEVEL SECURITY');
        DB::unprepared(<<<'SQL'
            CREATE POLICY ironcore_tenant_isolation ON saas_payment_corrections
            USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
            WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_payment_corrections');
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['gym_id', 'actor_role', 'created_at']);
            $table->dropColumn('actor_role');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP POLICY IF EXISTS ironcore_platform_audit_select ON audit_logs');
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
    }
};
