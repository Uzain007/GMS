<?php

use App\Enums\AccessCredentialStatus;
use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Enums\ClassBookingStatus;
use App\Enums\ClassSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_access_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('issued_by');
            // QR plaintext is returned once; only its SHA-256 digest persists.
            $table->char('credential_hash', 64);
            $table->string('credential_hint', 12);
            $table->string('status', 30)->default(AccessCredentialStatus::Active->value);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->cascadeOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'credential_hash']);
            $table->index(['gym_id', 'member_id', 'status']);
            $table->index(['gym_id', 'status', 'expires_at']);
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('membership_id');
            $table->uuid('branch_id');
            $table->uuid('access_credential_id')->nullable();
            $table->uuid('checked_in_by');
            $table->uuid('checked_out_by')->nullable();
            $table->string('method', 30)->default(AttendanceMethod::Manual->value);
            $table->string('status', 30)->default(AttendanceStatus::CheckedIn->value);
            $table->timestampTz('checked_in_at');
            $table->timestampTz('checked_out_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['gym_id', 'membership_id'])->references(['gym_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['gym_id', 'branch_id'])->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->foreign(['gym_id', 'access_credential_id'])->references(['gym_id', 'id'])->on('member_access_credentials')->restrictOnDelete();
            $table->foreign('checked_in_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('checked_out_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            // Tenant-leading indexes keep million-row attendance history bounded.
            $table->index(['gym_id', 'branch_id', 'checked_in_at', 'id']);
            $table->index(['gym_id', 'member_id', 'checked_in_at', 'id']);
            $table->index(['gym_id', 'status', 'checked_in_at', 'id']);
        });

        Schema::create('class_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('branch_id');
            $table->uuid('trainer_staff_profile_id')->nullable();
            $table->uuid('created_by');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedInteger('booked_count')->default(0);
            $table->unsignedInteger('waitlist_count')->default(0);
            $table->unsignedInteger('attended_count')->default(0);
            // The session row is locked before counters or FIFO sequence change.
            $table->unsignedBigInteger('next_waitlist_sequence')->default(1);
            $table->boolean('waitlist_enabled')->default(true);
            $table->timestampTz('booking_opens_at')->nullable();
            $table->timestampTz('booking_closes_at')->nullable();
            $table->string('status', 30)->default(ClassSessionStatus::Scheduled->value);
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'branch_id'])->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->foreign(['gym_id', 'trainer_staff_profile_id'])->references(['gym_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'branch_id', 'starts_at', 'id']);
            $table->index(['gym_id', 'trainer_staff_profile_id', 'starts_at', 'id']);
            $table->index(['gym_id', 'status', 'starts_at', 'id']);
        });

        Schema::create('class_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('class_session_id');
            $table->uuid('member_id');
            $table->uuid('membership_id');
            $table->uuid('booked_by');
            $table->string('status', 30)->default(ClassBookingStatus::Booked->value);
            $table->unsignedBigInteger('waitlist_sequence')->nullable();
            $table->timestampTz('booked_at');
            $table->timestampTz('promoted_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'class_session_id'])->references(['gym_id', 'id'])->on('class_sessions')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['gym_id', 'membership_id'])->references(['gym_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign('booked_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'class_session_id', 'status', 'waitlist_sequence']);
            $table->index(['gym_id', 'member_id', 'status', 'booked_at']);
            $table->index(['gym_id', 'status', 'booked_at', 'id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX member_access_credentials_one_active_unique
                ON member_access_credentials (gym_id, member_id)
                WHERE status = 'active'
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX attendance_records_one_open_unique
                ON attendance_records (gym_id, member_id)
                WHERE status = 'checked_in'
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX class_bookings_one_active_unique
                ON class_bookings (gym_id, class_session_id, member_id)
                WHERE status IN ('booked', 'waitlisted', 'attended')
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_bookings');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('member_access_credentials');
    }
};
