<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_progress_measurements', function (Blueprint $table): void {
            $table->string('status', 20)->default('active');
            $table->uuid('replaces_measurement_id')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestampTz('voided_at')->nullable();

            // Corrections append one tenant-bound replacement instead of
            // rewriting health/progress history in place.
            $table->foreign(['gym_id', 'replaces_measurement_id'], 'progress_measurements_replacement_foreign')
                ->references(['gym_id', 'id'])->on('member_progress_measurements')->restrictOnDelete();
            $table->foreign('voided_by', 'progress_measurements_voided_by_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'replaces_measurement_id'], 'progress_measurements_one_replacement_unique');
            // Live progress charts remain tenant/member-leading while corrected
            // and voided evidence can still be retrieved for authorised audits.
            $table->index(
                ['gym_id', 'member_id', 'status', 'measured_at', 'id'],
                'progress_measurements_status_time_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('member_progress_measurements', function (Blueprint $table): void {
            $table->dropIndex('progress_measurements_status_time_index');
            $table->dropUnique('progress_measurements_one_replacement_unique');
            $table->dropForeign('progress_measurements_replacement_foreign');
            $table->dropForeign('progress_measurements_voided_by_foreign');
            $table->dropColumn(['status', 'replaces_measurement_id', 'voided_by', 'voided_at']);
        });
    }
};
