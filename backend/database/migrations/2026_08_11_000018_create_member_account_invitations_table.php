<?php

use App\Enums\InvitationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_account_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('invited_by');
            $table->uuid('accepted_user_id')->nullable();
            $table->string('email', 254);
            // Only the tenant-scoped SHA-256 digest persists. The activation
            // value is returned once and remains outside logs and audit data.
            $table->char('token_hash', 64);
            $table->string('status', 30)->default(InvitationStatus::Pending->value);
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('accepted_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['gym_id', 'member_id'])
                ->references(['gym_id', 'id'])->on('members')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'token_hash']);
            $table->index(['gym_id', 'member_id', 'status', 'created_at']);
            $table->index(['gym_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_account_invitations');
    }
};
