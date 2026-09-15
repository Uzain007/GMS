<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->unsignedSmallInteger('grace_period_days')->default(7)->after('auto_renew');
            $table->timestampTz('billing_restricted_at')->nullable()->after('grace_period_days');
            // Tenant-leading lookup supports the daily restriction sweep without
            // weakening the model scope or PostgreSQL RLS boundary.
            $table->index(['gym_id', 'billing_restricted_at', 'status'], 'memberships_billing_restriction_idx');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->date('billing_cycle_date')->nullable()->after('membership_id');
            $table->timestampTz('grace_ends_at')->nullable()->after('due_at');
            // A tenant-local cycle key makes repeated scheduler runs idempotent;
            // nullable manual invoices retain their existing behaviour.
            $table->unique(['gym_id', 'membership_id', 'billing_cycle_date'], 'invoices_membership_cycle_unique');
            $table->index(['gym_id', 'status', 'due_at', 'billing_cycle_date'], 'invoices_billing_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_billing_lifecycle_idx');
            $table->dropUnique('invoices_membership_cycle_unique');
            $table->dropColumn(['billing_cycle_date', 'grace_ends_at']);
        });
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropIndex('memberships_billing_restriction_idx');
            $table->dropColumn(['grace_period_days', 'billing_restricted_at']);
        });
    }
};
