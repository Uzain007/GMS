<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gym_bank_transfer_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->boolean('enabled')->default(false);
            // Financial instructions are encrypted by the model; only a
            // tenant-authorised admin or linked member can receive plaintext.
            $table->text('account_name')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('account_number_or_iban')->nullable();
            $table->text('routing_details')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->uuid('updated_by');
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            // One row per tenant is both the lookup path and tenant-leading
            // uniqueness boundary required by settings reads.
            $table->unique(['gym_id']);
            $table->unique(['gym_id', 'id']);
        });

        Schema::table('bank_transfer_receipts', function (Blueprint $table): void {
            $table->date('transferred_on')->nullable()->after('bank_reference');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE gym_bank_transfer_settings ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE gym_bank_transfer_settings FORCE ROW LEVEL SECURITY');
            DB::unprepared(<<<'SQL'
                CREATE POLICY ironcore_tenant_isolation ON gym_bank_transfer_settings
                USING (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
                WITH CHECK (gym_id = nullif(current_setting('ironcore.current_gym_id', true), '')::uuid)
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('bank_transfer_receipts', function (Blueprint $table): void {
            $table->dropColumn('transferred_on');
        });
        Schema::dropIfExists('gym_bank_transfer_settings');
    }
};
