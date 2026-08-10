<?php

use App\Enums\Currency;
use App\Enums\GymStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gyms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 100)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->string('base_currency', 3)->default(Currency::GBP->value);
            $table->char('country_code', 2);
            $table->string('timezone', 80)->default('Europe/London');
            $table->string('status', 30)->default(GymStatus::Trial->value)->index();
            $table->timestampTz('trial_ends_at')->nullable()->index();
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });

        Schema::create('gym_user', function (Blueprint $table): void {
            $table->uuid('gym_id');
            $table->uuid('user_id');
            $table->string('role', 40);
            $table->string('status', 30)->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->primary(['gym_id', 'user_id']);
            $table->index(['gym_id', 'role', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_user');
        Schema::dropIfExists('gyms');
    }
};
