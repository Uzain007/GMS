<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGatewayStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_gateway_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->string('provider', 30)->default(PaymentProvider::Stripe->value);
            $table->string('provider_account_id', 120)->nullable();
            $table->string('status', 30)->default(PaymentGatewayStatus::Pending->value);
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->char('country_code', 2);
            $table->char('default_currency', 3);
            $table->jsonb('requirements')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'provider']);
            // This global opaque-ID index is used only after a verified provider
            // signature to resolve a webhook into an explicit tenant context.
            $table->unique(['provider', 'provider_account_id']);
            $table->index(['gym_id', 'status', 'updated_at']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('membership_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('created_by');
            $table->string('number', 50);
            $table->string('status', 30)->default(InvoiceStatus::Open->value);
            $table->char('currency', 3);
            // All calculations use integer minor units; totals are server-derived.
            $table->unsignedBigInteger('subtotal_amount_minor');
            $table->unsignedBigInteger('tax_amount_minor')->default(0);
            $table->unsignedBigInteger('total_amount_minor');
            $table->unsignedBigInteger('paid_amount_minor')->default(0);
            $table->unsignedBigInteger('due_amount_minor');
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['gym_id', 'membership_id'])->references(['gym_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['gym_id', 'branch_id'])->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'number']);
            $table->index(['gym_id', 'status', 'due_at']);
            $table->index(['gym_id', 'member_id', 'created_at']);
            $table->index(['gym_id', 'membership_id', 'created_at']);
            $table->index(['gym_id', 'branch_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('invoice_id');
            $table->string('description', 240);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('subtotal_amount_minor');
            $table->unsignedBigInteger('tax_amount_minor')->default(0);
            $table->unsignedBigInteger('total_amount_minor');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'invoice_id'])->references(['gym_id', 'id'])->on('invoices')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'invoice_id', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('membership_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('recorded_by');
            $table->string('receipt_number', 50);
            $table->string('provider', 30)->default(PaymentProvider::Manual->value);
            $table->string('method', 30);
            $table->string('status', 30)->default(PaymentStatus::Pending->value);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->char('currency', 3);
            $table->string('idempotency_key', 120)->nullable();
            $table->string('provider_checkout_id', 180)->nullable();
            $table->string('provider_payment_id', 180)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['gym_id', 'membership_id'])->references(['gym_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['gym_id', 'invoice_id'])->references(['gym_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['gym_id', 'branch_id'])->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'receipt_number']);
            $table->unique(['gym_id', 'idempotency_key']);
            $table->unique(['gym_id', 'provider', 'provider_checkout_id']);
            $table->unique(['gym_id', 'provider', 'provider_payment_id']);
            $table->index(['gym_id', 'status', 'paid_at']);
            $table->index(['gym_id', 'member_id', 'created_at']);
            $table->index(['gym_id', 'invoice_id', 'created_at']);
            $table->index(['gym_id', 'method', 'paid_at']);
            $table->index(['gym_id', 'branch_id', 'paid_at']);
        });

        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('payment_id');
            $table->uuid('recorded_by');
            $table->string('status', 30);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider_refund_id', 180)->nullable();
            $table->text('reason');
            $table->timestampTz('refunded_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'payment_id'])->references(['gym_id', 'id'])->on('payments')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'provider_refund_id']);
            $table->index(['gym_id', 'payment_id', 'created_at']);
            $table->index(['gym_id', 'status', 'created_at']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->string('provider', 30);
            $table->string('provider_account_id', 120);
            $table->string('provider_event_id', 180);
            $table->string('event_type', 120);
            $table->char('payload_hash', 64);
            $table->string('status', 30)->default('processing');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            // Provider events are unique only inside their resolved tenant; the
            // verified account identifier is used to bind that tenant first.
            $table->unique(['gym_id', 'provider', 'provider_event_id']);
            $table->index(['gym_id', 'status', 'created_at']);
            $table->index(['gym_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
