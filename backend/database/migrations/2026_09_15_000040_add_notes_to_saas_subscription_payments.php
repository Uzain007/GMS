<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_subscription_payments', function (Blueprint $table): void {
            // A note belongs to the original tenant-scoped submission. Financial
            // corrections continue to use the append-only correction ledger.
            $table->text('notes')->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('saas_subscription_payments', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
