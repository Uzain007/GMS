<?php

use App\Enums\PaymentProvider;
use App\Enums\SaasInvoiceStatus;
use App\Enums\SaasPlanStatus;
use App\Enums\SaasSubscriptionStatus;
use App\Enums\SubscriptionCheckoutStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 30)->default(SaasPlanStatus::Draft->value);
            $table->jsonb('feature_limits')->default('{}');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->string('provider', 30)->default(PaymentProvider::Stripe->value);
            $table->string('provider_product_id', 180)->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_product_id']);
            $table->index(['status', 'sort_order', 'name']);
        });

        Schema::create('saas_plan_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('saas_plan_id');
            $table->char('currency', 3);
            $table->string('billing_interval', 20);
            // SaaS prices use the same exact minor-unit contract as tenant money.
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('active')->default(true);
            $table->string('provider', 30)->default(PaymentProvider::Stripe->value);
            $table->string('provider_price_id', 180)->nullable();
            $table->timestampsTz();

            $table->foreign('saas_plan_id')->references('id')->on('saas_plans')->restrictOnDelete();
            $table->unique(['provider', 'provider_price_id']);
            $table->index(['saas_plan_id', 'active', 'currency', 'billing_interval']);
        });

        Schema::create('platform_billing_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->string('provider', 30)->default(PaymentProvider::Stripe->value);
            $table->string('provider_customer_id', 180);
            $table->string('billing_email', 254);
            $table->string('billing_name', 200)->nullable();
            $table->char('country_code', 2);
            $table->char('default_currency', 3);
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'provider']);
            // This opaque global key is queried only after Billing webhook HMAC verification.
            $table->unique(['provider', 'provider_customer_id']);
            $table->index(['gym_id', 'updated_at']);
        });

        Schema::create('gym_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('billing_customer_id');
            $table->uuid('saas_plan_id');
            $table->uuid('saas_plan_price_id');
            $table->string('provider', 30)->default(PaymentProvider::Stripe->value);
            $table->string('provider_subscription_id', 180);
            $table->string('status', 30)->default(SaasSubscriptionStatus::Incomplete->value);
            // Accepted price and entitlements never change when the catalogue changes.
            $table->string('plan_code_snapshot', 60);
            $table->string('plan_name_snapshot', 160);
            $table->jsonb('feature_limits_snapshot')->default('{}');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('billing_interval', 20);
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('latest_invoice_id', 180)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'billing_customer_id'])->references(['gym_id', 'id'])->on('platform_billing_customers')->restrictOnDelete();
            $table->foreign('saas_plan_id')->references('id')->on('saas_plans')->restrictOnDelete();
            $table->foreign('saas_plan_price_id')->references('id')->on('saas_plan_prices')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['provider', 'provider_subscription_id']);
            $table->index(['gym_id', 'status', 'current_period_end']);
            $table->index(['gym_id', 'billing_customer_id', 'created_at']);
        });

        Schema::create('subscription_checkout_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('created_by');
            $table->uuid('saas_plan_price_id');
            $table->string('idempotency_key', 120);
            $table->string('provider_session_id', 180);
            $table->string('status', 30)->default(SubscriptionCheckoutStatus::Open->value);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('saas_plan_price_id')->references('id')->on('saas_plan_prices')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'idempotency_key']);
            $table->unique('provider_session_id');
            $table->index(['gym_id', 'status', 'created_at']);
        });

        Schema::create('saas_billing_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('billing_customer_id');
            $table->uuid('gym_subscription_id')->nullable();
            $table->string('provider_invoice_id', 180);
            $table->string('number', 80)->nullable();
            $table->string('status', 30)->default(SaasInvoiceStatus::Draft->value);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_due_minor')->default(0);
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->unsignedBigInteger('amount_remaining_minor')->default(0);
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf_url')->nullable();
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'billing_customer_id'])->references(['gym_id', 'id'])->on('platform_billing_customers')->restrictOnDelete();
            $table->foreign(['gym_id', 'gym_subscription_id'])->references(['gym_id', 'id'])->on('gym_subscriptions')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique('provider_invoice_id');
            $table->index(['gym_id', 'status', 'due_at']);
            $table->index(['gym_id', 'gym_subscription_id', 'period_end']);
        });

        Schema::create('saas_billing_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->string('provider_customer_id', 180);
            $table->string('provider_event_id', 180);
            $table->string('event_type', 120);
            $table->char('payload_hash', 64);
            $table->string('status', 30)->default('processing');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'provider_event_id']);
            $table->index(['gym_id', 'status', 'created_at']);
            $table->index(['gym_id', 'event_type', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Only one catalogue price can be currently selectable for a tier,
            // currency and interval; historical inactive prices remain intact.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX saas_plan_prices_active_catalog_unique
                ON saas_plan_prices (saas_plan_id, currency, billing_interval)
                WHERE active = true
            SQL);
            // Multiple historical subscriptions are retained, but a gym cannot
            // hold two concurrent non-terminal IronCore contracts.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX gym_subscriptions_one_current_unique
                ON gym_subscriptions (gym_id)
                WHERE status IN ('incomplete', 'trialing', 'active', 'past_due', 'unpaid', 'paused')
            SQL);
            // Different browser retries must not create parallel live hosted
            // sessions that could produce two subscriptions for one gym.
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX subscription_checkout_sessions_one_open_unique
                ON subscription_checkout_sessions (gym_id)
                WHERE status = 'open'
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_billing_webhook_events');
        Schema::dropIfExists('saas_billing_invoices');
        Schema::dropIfExists('subscription_checkout_sessions');
        Schema::dropIfExists('gym_subscriptions');
        Schema::dropIfExists('platform_billing_customers');
        Schema::dropIfExists('saas_plan_prices');
        Schema::dropIfExists('saas_plans');
    }
};
