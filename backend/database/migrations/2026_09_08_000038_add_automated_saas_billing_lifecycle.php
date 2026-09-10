<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_subscriptions', function (Blueprint $table): void {
            $table->timestampTz('next_billing_at')->nullable()->after('current_period_end');
            $table->unsignedSmallInteger('grace_period_days')->default(15)->after('next_billing_at');
            $table->timestampTz('billing_restricted_at')->nullable()->after('grace_period_days');
            $table->timestampTz('billing_override_until')->nullable()->after('billing_restricted_at');
            $table->uuid('billing_override_by')->nullable()->after('billing_override_until');
            $table->text('billing_override_reason')->nullable()->after('billing_override_by');
            $table->foreign('billing_override_by')->references('id')->on('users')->nullOnDelete();
            // Scheduler and access middleware resolve the current tenant's next
            // action without scanning historical contracts.
            $table->index(['gym_id', 'next_billing_at', 'status'], 'gym_subscriptions_next_billing_idx');
            $table->index(['gym_id', 'billing_restricted_at'], 'gym_subscriptions_restriction_idx');
        });

        Schema::table('saas_billing_invoices', function (Blueprint $table): void {
            $table->timestampTz('available_at')->nullable()->after('invoice_pdf_url');
            $table->timestampTz('grace_ends_at')->nullable()->after('due_at');
            $table->timestampTz('voided_at')->nullable()->after('paid_at');
            $table->uuid('voided_by')->nullable()->after('voided_at');
            $table->text('void_reason')->nullable()->after('voided_by');
            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['gym_id', 'status', 'available_at'], 'saas_invoices_availability_idx');
            $table->index(['gym_id', 'status', 'grace_ends_at'], 'saas_invoices_grace_idx');
        });

        Schema::table('saas_subscription_payments', function (Blueprint $table): void {
            $table->date('payment_date')->nullable()->after('reference');
            $table->unsignedBigInteger('refunded_amount_minor')->default(0)->after('amount_minor');
        });

        Schema::table('saas_payment_corrections', function (Blueprint $table): void {
            // Effective values are append-only overlays; the settled payment row
            // remains immutable evidence of what was originally recorded.
            $table->date('payment_date')->nullable()->after('method');
            $table->unsignedBigInteger('amount_minor')->nullable()->after('payment_date');
        });

        Schema::create('saas_payment_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('saas_subscription_payment_id');
            $table->uuid('recorded_by');
            $table->string('status', 30)->default('succeeded');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('reason');
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->restrictOnDelete();
            $table->foreign(['gym_id', 'saas_subscription_payment_id'])
                ->references(['gym_id', 'id'])->on('saas_subscription_payments')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'saas_subscription_payment_id', 'created_at'], 'saas_refunds_payment_idx');
            $table->index(['gym_id', 'status', 'created_at'], 'saas_refunds_status_idx');
        });

        Schema::create('saas_billing_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('saas_billing_invoice_id');
            $table->uuid('recipient_user_id');
            // Encrypted model casts protect the owner address while durable
            // idempotency proves each daily reminder was queued at most once.
            $table->text('destination');
            $table->string('template_key', 80);
            $table->date('notification_date');
            $table->string('idempotency_key', 160);
            $table->string('status', 30)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 80)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->restrictOnDelete();
            $table->foreign(['gym_id', 'saas_billing_invoice_id'])
                ->references(['gym_id', 'id'])->on('saas_billing_invoices')->restrictOnDelete();
            $table->foreign('recipient_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'idempotency_key']);
            $table->index(['gym_id', 'status', 'created_at'], 'saas_notifications_status_idx');
            $table->index(['gym_id', 'saas_billing_invoice_id', 'notification_date'], 'saas_notifications_invoice_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['saas_payment_refunds', 'saas_billing_notifications'] as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::unprepared(<<<SQL
                    CREATE POLICY ironcore_tenant_isolation ON {$table}
                    USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
                    WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
                SQL);
            }
        }

        // Existing paid subscriptions already use period_end as their renewal
        // boundary; preserving it avoids manufacturing a new commercial date.
        $driver = DB::connection()->getDriverName();
        DB::table('gyms')->orderBy('id')->each(function (object $gym) use ($driver): void {
            if ($driver === 'pgsql') {
                DB::statement("select set_config('ironcore.current_gym_id', ?, false)", [$gym->id]);
            }
            DB::table('gym_subscriptions')->where('gym_id', $gym->id)
                ->whereNull('next_billing_at')->whereNotNull('current_period_end')
                ->update(['next_billing_at' => DB::raw('current_period_end')]);
        });
        if ($driver === 'pgsql') {
            DB::statement("select set_config('ironcore.current_gym_id', '', false)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_billing_notifications');
        Schema::dropIfExists('saas_payment_refunds');

        Schema::table('saas_payment_corrections', function (Blueprint $table): void {
            $table->dropColumn(['payment_date', 'amount_minor']);
        });
        Schema::table('saas_subscription_payments', function (Blueprint $table): void {
            $table->dropColumn(['payment_date', 'refunded_amount_minor']);
        });
        Schema::table('saas_billing_invoices', function (Blueprint $table): void {
            $table->dropForeign(['voided_by']);
            $table->dropIndex('saas_invoices_availability_idx');
            $table->dropIndex('saas_invoices_grace_idx');
            $table->dropColumn(['available_at', 'grace_ends_at', 'voided_at', 'voided_by', 'void_reason']);
        });
        Schema::table('gym_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['billing_override_by']);
            $table->dropIndex('gym_subscriptions_next_billing_idx');
            $table->dropIndex('gym_subscriptions_restriction_idx');
            $table->dropColumn([
                'next_billing_at', 'grace_period_days', 'billing_restricted_at',
                'billing_override_until', 'billing_override_by', 'billing_override_reason',
            ]);
        });
    }
};
