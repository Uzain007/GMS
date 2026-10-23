<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** @var list<string> */
    private array $tenantTables = ['staff_attendance_records', 'saas_plan_change_requests', 'membership_renewals'];

    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->string('checkout_type', 20)->nullable()->after('status');
        });
        Schema::table('staff_profiles', function (Blueprint $table): void {
            $table->timestampTz('lifecycle_changed_at')->nullable()->after('terminated_at');
            $table->text('lifecycle_reason')->nullable()->after('lifecycle_changed_at');
        });
        Schema::table('members', function (Blueprint $table): void {
            $table->timestampTz('lifecycle_changed_at')->nullable()->after('archived_at');
            $table->text('lifecycle_reason')->nullable()->after('lifecycle_changed_at');
        });

        Schema::create('staff_attendance_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('staff_profile_id');
            $table->uuid('branch_id');
            $table->uuid('checked_in_by');
            $table->uuid('checked_out_by')->nullable();
            $table->string('method', 30)->default('manual');
            $table->string('status', 30)->default('checked_in');
            $table->string('checkout_type', 20)->nullable();
            $table->timestampTz('checked_in_at');
            $table->timestampTz('checked_out_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'staff_profile_id'])->references(['gym_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign(['gym_id', 'branch_id'])->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->foreign('checked_in_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('checked_out_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'status', 'checked_in_at', 'id']);
            $table->index(['gym_id', 'staff_profile_id', 'checked_in_at', 'id']);
        });

        Schema::create('saas_plan_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('gym_subscription_id');
            $table->uuid('current_saas_plan_id');
            $table->uuid('current_saas_plan_price_id');
            $table->uuid('requested_saas_plan_id');
            $table->uuid('requested_saas_plan_price_id');
            $table->uuid('requested_by');
            $table->uuid('reviewed_by')->nullable();
            $table->string('change_type', 20);
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->text('review_reason')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'gym_subscription_id'])->references(['gym_id', 'id'])->on('gym_subscriptions')->restrictOnDelete();
            $table->foreign('current_saas_plan_id')->references('id')->on('saas_plans')->restrictOnDelete();
            $table->foreign('current_saas_plan_price_id')->references('id')->on('saas_plan_prices')->restrictOnDelete();
            $table->foreign('requested_saas_plan_id')->references('id')->on('saas_plans')->restrictOnDelete();
            $table->foreign('requested_saas_plan_price_id')->references('id')->on('saas_plan_prices')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'status', 'requested_at', 'id']);
        });

        Schema::create('membership_renewals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('membership_id');
            $table->uuid('invoice_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('pending_payment');
            $table->string('source', 30)->default('automatic');
            $table->timestampTz('renewed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'membership_id'])->references(['gym_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['gym_id', 'invoice_id'])->references(['gym_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'invoice_id']);
            $table->index(['gym_id', 'membership_id', 'period_start', 'id']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::unprepared("CREATE UNIQUE INDEX staff_attendance_one_open_unique ON staff_attendance_records (gym_id, staff_profile_id) WHERE status = 'checked_in'");
        DB::unprepared("CREATE UNIQUE INDEX saas_plan_change_one_pending_unique ON saas_plan_change_requests (gym_id) WHERE status = 'pending'");
        foreach ($this->tenantTables as $table) {
            // Every new operational record is tenant-owned and remains fail-closed under raw SQL.
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::unprepared("CREATE POLICY ironcore_tenant_isolation ON {$table} USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid) WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (array_reverse($this->tenantTables) as $table) {
                DB::unprepared("DROP POLICY IF EXISTS ironcore_tenant_isolation ON {$table}");
                DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
        }
        Schema::dropIfExists('membership_renewals');
        Schema::dropIfExists('saas_plan_change_requests');
        Schema::dropIfExists('staff_attendance_records');
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn(['lifecycle_changed_at', 'lifecycle_reason']));
        Schema::table('staff_profiles', fn (Blueprint $table) => $table->dropColumn(['lifecycle_changed_at', 'lifecycle_reason']));
        Schema::table('attendance_records', fn (Blueprint $table) => $table->dropColumn('checkout_type'));
    }
};
