<?php

use App\Enums\MemberExportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_data_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('requested_by');
            $table->string('status', 30)->default(MemberExportStatus::Queued->value);
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            // Polling and expiry cleanup always start from the selected tenant.
            $table->index(['gym_id', 'member_id', 'created_at']);
            $table->index(['gym_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_data_exports');
    }
};
