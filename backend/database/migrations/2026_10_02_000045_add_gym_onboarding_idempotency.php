<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('gyms', function (Blueprint $table): void {
            // Gym creation starts before a tenant exists, so its retry key belongs
            // on the platform tenant registry rather than in a tenant-owned table.
            $table->string('onboarding_idempotency_key', 120)->nullable();
            $table->char('onboarding_request_hash', 64)->nullable();
            $table->unique('onboarding_idempotency_key', 'gyms_onboarding_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('gyms', function (Blueprint $table): void {
            $table->dropUnique('gyms_onboarding_idempotency_unique');
            $table->dropColumn(['onboarding_idempotency_key', 'onboarding_request_hash']);
        });
    }
};
