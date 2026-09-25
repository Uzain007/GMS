<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transfer_receipts', function (Blueprint $table): void {
            // Keep immutable financial evidence while recording only when its
            // separately retained private object has reached end of life.
            $table->timestampTz('file_deleted_at')->nullable()->after('content_sha256');
            $table->index(['gym_id', 'file_deleted_at', 'created_at'], 'bank_receipts_retention_idx');
        });

        Schema::table('saas_subscription_payments', function (Blueprint $table): void {
            $table->timestampTz('receipt_deleted_at')->nullable()->after('receipt_sha256');
            $table->index(['gym_id', 'receipt_deleted_at', 'created_at'], 'saas_receipts_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transfer_receipts', function (Blueprint $table): void {
            $table->dropIndex('bank_receipts_retention_idx');
            $table->dropColumn('file_deleted_at');
        });
        Schema::table('saas_subscription_payments', function (Blueprint $table): void {
            $table->dropIndex('saas_receipts_retention_idx');
            $table->dropColumn('receipt_deleted_at');
        });
    }
};
