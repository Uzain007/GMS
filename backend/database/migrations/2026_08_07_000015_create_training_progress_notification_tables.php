<?php

use App\Enums\NotificationDeliveryStatus;
use App\Enums\TrainerAssignmentStatus;
use App\Enums\WorkoutPlanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trainer_member_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('trainer_staff_profile_id');
            $table->uuid('member_id');
            $table->uuid('assigned_by');
            $table->string('status', 30)->default(TrainerAssignmentStatus::Active->value);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'trainer_staff_profile_id'])->references(['gym_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            // Trainer/member dashboards remain tenant-prefixed at large scale.
            $table->index(['gym_id', 'trainer_staff_profile_id', 'status', 'starts_on']);
            $table->index(['gym_id', 'member_id', 'status', 'starts_on']);
        });

        Schema::create('workout_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('trainer_staff_profile_id');
            $table->uuid('created_by');
            $table->string('title', 160);
            $table->string('goal', 240)->nullable();
            $table->text('notes')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 30)->default(WorkoutPlanStatus::Draft->value);
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['gym_id', 'trainer_staff_profile_id'])->references(['gym_id', 'id'])->on('staff_profiles')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'member_id', 'status', 'starts_on', 'id']);
            $table->index(['gym_id', 'trainer_staff_profile_id', 'status', 'starts_on', 'id']);
        });

        Schema::create('workout_plan_exercises', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('workout_plan_id');
            $table->string('name', 160);
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('day_number')->default(1);
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedSmallInteger('target_sets')->nullable();
            $table->unsignedSmallInteger('target_reps_min')->nullable();
            $table->unsignedSmallInteger('target_reps_max')->nullable();
            // Exact grams avoid floating-point drift across kg/lb displays.
            $table->unsignedBigInteger('target_load_grams')->nullable();
            $table->unsignedInteger('target_duration_seconds')->nullable();
            $table->unsignedInteger('rest_seconds')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'workout_plan_id'])->references(['gym_id', 'id'])->on('workout_plans')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'workout_plan_id', 'day_number', 'sort_order'], 'workout_exercises_order_unique');
            $table->index(['gym_id', 'workout_plan_id', 'day_number', 'sort_order']);
        });

        Schema::create('workout_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('workout_plan_id');
            $table->uuid('member_id');
            $table->uuid('logged_by');
            $table->timestampTz('performed_at');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'workout_plan_id'])->references(['gym_id', 'id'])->on('workout_plans')->restrictOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign('logged_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'member_id', 'performed_at', 'id']);
            $table->index(['gym_id', 'workout_plan_id', 'performed_at', 'id']);
        });

        Schema::create('workout_set_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('workout_session_id');
            $table->uuid('workout_plan_exercise_id');
            $table->unsignedSmallInteger('set_number');
            $table->unsignedSmallInteger('reps')->nullable();
            $table->unsignedBigInteger('load_grams')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('distance_metres')->nullable();
            $table->unsignedSmallInteger('rpe')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'workout_session_id'])->references(['gym_id', 'id'])->on('workout_sessions')->cascadeOnDelete();
            $table->foreign(['gym_id', 'workout_plan_exercise_id'])->references(['gym_id', 'id'])->on('workout_plan_exercises')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'workout_session_id', 'workout_plan_exercise_id', 'set_number'], 'workout_set_logs_position_unique');
            $table->index(['gym_id', 'workout_session_id', 'set_number']);
        });

        Schema::create('member_progress_measurements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('recorded_by');
            $table->string('metric', 40);
            $table->bigInteger('value_milli');
            $table->string('unit', 20);
            $table->timestampTz('measured_at');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            // Member/metric chronology serves progress charts without offsets.
            $table->index(['gym_id', 'member_id', 'metric', 'measured_at', 'id'], 'member_progress_metric_time_index');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('push_enabled')->default(false);
            $table->boolean('class_reminders_enabled')->default(true);
            $table->boolean('workout_reminders_enabled')->default(true);
            $table->boolean('payment_reminders_enabled')->default(true);
            $table->boolean('marketing_enabled')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('timezone', 80)->default('Europe/London');
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->cascadeOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'member_id']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('triggered_by')->nullable();
            $table->string('channel', 20);
            $table->string('template_key', 80);
            // Laravel encrypted cast protects email, phone and push endpoints.
            $table->text('destination');
            $table->jsonb('variables');
            $table->string('idempotency_key', 120);
            $table->string('status', 30)->default(NotificationDeliveryStatus::Queued->value);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id', 180)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampTz('scheduled_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'member_id'])->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'idempotency_key']);
            $table->index(['gym_id', 'status', 'scheduled_at', 'id']);
            $table->index(['gym_id', 'member_id', 'created_at', 'id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX trainer_member_assignments_one_active_unique
                ON trainer_member_assignments (gym_id, trainer_staff_profile_id, member_id)
                WHERE status = 'active'
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX workout_plans_one_active_member_unique
                ON workout_plans (gym_id, member_id)
                WHERE status = 'active'
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('member_progress_measurements');
        Schema::dropIfExists('workout_set_logs');
        Schema::dropIfExists('workout_sessions');
        Schema::dropIfExists('workout_plan_exercises');
        Schema::dropIfExists('workout_plans');
        Schema::dropIfExists('trainer_member_assignments');
    }
};
