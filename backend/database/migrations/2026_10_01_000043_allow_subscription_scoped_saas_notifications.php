<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_billing_notifications', function (Blueprint $table): void {
            $table->dropForeign(['gym_id', 'saas_billing_invoice_id']);
        });

        Schema::table('saas_billing_notifications', function (Blueprint $table): void {
            // Trial notices belong to the tenant subscription and must not need
            // a fabricated invoice. Existing invoice reminders remain intact.
            $table->uuid('gym_subscription_id')->nullable()->after('gym_id');
            $table->uuid('saas_billing_invoice_id')->nullable()->change();

            $table->foreign(['gym_id', 'gym_subscription_id'], 'saas_notifications_subscription_fk')
                ->references(['gym_id', 'id'])->on('gym_subscriptions')->restrictOnDelete();
            $table->foreign(['gym_id', 'saas_billing_invoice_id'], 'saas_notifications_invoice_fk')
                ->references(['gym_id', 'id'])->on('saas_billing_invoices')->restrictOnDelete();
            $table->index(
                ['gym_id', 'gym_subscription_id', 'notification_date'],
                'saas_notifications_subscription_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('saas_billing_notifications', function (Blueprint $table): void {
            $table->dropForeign('saas_notifications_subscription_fk');
            $table->dropForeign('saas_notifications_invoice_fk');
            $table->dropIndex('saas_notifications_subscription_idx');
            $table->dropColumn('gym_subscription_id');
        });

        Schema::table('saas_billing_notifications', function (Blueprint $table): void {
            // Rollback deliberately fails rather than deleting any trial notice
            // if nullable invoice rows already exist.
            $table->uuid('saas_billing_invoice_id')->nullable(false)->change();
            $table->foreign(['gym_id', 'saas_billing_invoice_id'])
                ->references(['gym_id', 'id'])->on('saas_billing_invoices')->restrictOnDelete();
        });
    }
};
