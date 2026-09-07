<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Password-change enforcement belongs to the platform identity, not
            // a tenant profile, so one credential cannot bypass setup elsewhere.
            $table->boolean('must_change_password')->default(false)->after('auth_version');
            $table->timestampTz('last_login_at')->nullable()->after('must_change_password');
        });

        Schema::table('gym_user', function (Blueprint $table): void {
            // These fields describe only this gym's owner onboarding lifecycle;
            // the existing tenant-leading key and forced RLS remain authoritative.
            $table->string('setup_method', 40)->nullable()->after('status');
            $table->timestampTz('invite_sent_at')->nullable()->after('joined_at');
            $table->timestampTz('setup_completed_at')->nullable()->after('invite_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('gym_user', function (Blueprint $table): void {
            $table->dropColumn(['setup_method', 'invite_sent_at', 'setup_completed_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['must_change_password', 'last_login_at']);
        });
    }
};
