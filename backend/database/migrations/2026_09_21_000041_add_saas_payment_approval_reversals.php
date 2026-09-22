<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_payment_approval_reversals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('saas_subscription_payment_id');
            $table->uuid('reversed_by');
            // Snapshot the projections that existed when finance reversed the
            // mistaken approval; the original approval row remains untouched.
            $table->string('previous_payment_status', 30);
            $table->string('invoice_status_before', 30);
            $table->unsignedBigInteger('invoice_amount_paid_before');
            $table->unsignedBigInteger('invoice_amount_remaining_before');
            $table->string('subscription_status_before', 30)->nullable();
            $table->text('reason');
            $table->timestampTz('reversed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('gym_id')->references('id')->on('gyms')->restrictOnDelete();
            $table->foreign(['gym_id', 'saas_subscription_payment_id'], 'saas_approval_reversal_payment_fk')
                ->references(['gym_id', 'id'])->on('saas_subscription_payments')->restrictOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->restrictOnDelete();
            // A payment approval can be reversed only once, even under retries.
            $table->unique(['gym_id', 'saas_subscription_payment_id'], 'saas_approval_reversal_payment_unique');
            $table->index(['gym_id', 'reversed_at'], 'saas_approval_reversal_tenant_time_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Reversal evidence is financial tenant data and therefore uses the
            // same fail-closed PostgreSQL boundary as payments and refunds.
            DB::unprepared('ALTER TABLE saas_payment_approval_reversals ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE saas_payment_approval_reversals FORCE ROW LEVEL SECURITY');
            DB::unprepared(<<<'SQL'
                CREATE POLICY ironcore_tenant_isolation ON saas_payment_approval_reversals
                USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
                WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_payment_approval_reversals');
    }
};
