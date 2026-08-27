<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing ledgers use older provider-oriented names. Normalising them
        // preserves every row while exposing one business status vocabulary.
        $this->normaliseStatuses('succeeded', 'paid');
        $this->normaliseStatuses('failed', 'rejected');

        Schema::create('bank_transfer_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('payment_id');
            $table->uuid('member_id');
            $table->uuid('membership_id');
            $table->uuid('invoice_id')->nullable();
            $table->uuid('submitted_by');
            $table->uuid('reviewed_by')->nullable();
            $table->string('bank_reference', 160)->nullable();
            $table->string('storage_disk', 40);
            $table->string('storage_path', 500);
            $table->string('original_name', 240);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_sha256', 64);
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'payment_id'])->references(['gym_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['gym_id', 'membership_id'])->references(['gym_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['gym_id', 'invoice_id'])->references(['gym_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'payment_id']);
            // Tenant-leading access paths cover member history and the admin
            // queue without permitting a global object-storage lookup.
            $table->index(['gym_id', 'member_id', 'created_at']);
            $table->index(['gym_id', 'reviewed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_receipts');
        $this->normaliseStatuses('paid', 'succeeded');
        $this->normaliseStatuses('rejected', 'failed');
    }

    private function normaliseStatuses(string $from, string $to): void
    {
        $postgres = DB::connection()->getDriverName() === 'pgsql';

        try {
            foreach (DB::table('gyms')->orderBy('id')->pluck('id') as $gymId) {
                if ($postgres) {
                    // FORCE RLS stays enabled while each tenant ledger is
                    // upgraded under an explicit, fail-closed gym context.
                    DB::statement("select set_config('ironcore.current_gym_id', ?, false)", [$gymId]);
                }

                DB::table('payments')
                    ->where('gym_id', $gymId)
                    ->where('status', $from)
                    ->update(['status' => $to]);
            }
        } finally {
            if ($postgres) {
                DB::statement("select set_config('ironcore.current_gym_id', '', false)");
            }
        }
    }
};
