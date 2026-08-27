<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('saas_plans', function (Blueprint $table): void {
            // Catalogue availability is independent from any provider account;
            // Stripe IDs are synchronized only when a gym selects card checkout.
            $table->jsonb('payment_methods')->default('["stripe"]')->after('feature_limits');
        });

        Schema::create('saas_subscription_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('saas_plan_price_id');
            $table->uuid('gym_subscription_id')->nullable();
            $table->uuid('saas_billing_invoice_id')->nullable();
            $table->uuid('submitted_by');
            $table->uuid('reviewed_by')->nullable();
            $table->string('method', 30);
            $table->string('status', 30)->default('pending');
            // Platform subscription money uses its own tenant ledger and never
            // shares member-payment or connected-account records.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('idempotency_key', 120);
            $table->string('reference', 160)->nullable();
            $table->string('receipt_disk', 40)->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->string('receipt_original_name', 240)->nullable();
            $table->string('receipt_mime_type', 100)->nullable();
            $table->unsignedBigInteger('receipt_size_bytes')->nullable();
            $table->char('receipt_sha256', 64)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('saas_plan_price_id')->references('id')->on('saas_plan_prices')->restrictOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'gym_subscription_id'])->references(['gym_id', 'id'])->on('gym_subscriptions')->restrictOnDelete();
            $table->foreign(['gym_id', 'saas_billing_invoice_id'])->references(['gym_id', 'id'])->on('saas_billing_invoices')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'idempotency_key']);
            $table->index(['gym_id', 'status', 'created_at']);
            $table->index(['gym_id', 'method', 'status', 'created_at']);
            $table->index(['gym_id', 'saas_plan_price_id', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Manual subscription evidence is tenant-owned and therefore stays
            // fail-closed even for raw queries or an application table owner.
            DB::unprepared('ALTER TABLE saas_subscription_payments ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE saas_subscription_payments FORCE ROW LEVEL SECURITY');
            DB::unprepared(<<<'SQL'
                CREATE POLICY ironcore_tenant_isolation ON saas_subscription_payments
                USING (
                    gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid
                )
                WITH CHECK (
                    gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid
                )
            SQL);
            // One pending manual request prevents parallel cash/bank claims for
            // the same tenant while retaining every reviewed historical row.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX saas_subscription_payments_one_pending_unique
                ON saas_subscription_payments (gym_id)
                WHERE status = 'pending'
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_subscription_payments');
        Schema::table('saas_plans', function (Blueprint $table): void {
            $table->dropColumn('payment_methods');
        });
    }
};
