<?php

use App\Enums\BranchStatus;
use App\Enums\InvitationStatus;
use App\Enums\MemberStatus;
use App\Enums\PlanStatus;
use App\Enums\StaffStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gym_branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->string('name', 160);
            $table->string('code', 50);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->jsonb('address')->nullable();
            $table->string('status', 30)->default(BranchStatus::Active->value);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            // Composite identity lets child foreign keys prove both record and tenant.
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'code']);
            $table->index(['gym_id', 'status', 'name']);
            $table->index(['gym_id', 'is_primary']);
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('home_branch_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('member_number', 50);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('status', 30)->default(MemberStatus::Lead->value);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['gym_id', 'home_branch_id'])
                ->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'member_number']);
            $table->unique(['gym_id', 'user_id']);
            // All high-volume list and lookup indexes start at the tenant boundary.
            $table->index(['gym_id', 'status', 'created_at']);
            $table->index(['gym_id', 'last_name', 'first_name']);
            $table->index(['gym_id', 'email']);
            $table->index(['gym_id', 'home_branch_id', 'status']);
        });

        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('user_id');
            $table->uuid('home_branch_id')->nullable();
            $table->string('employee_number', 50);
            $table->string('job_title', 120)->nullable();
            $table->string('status', 30)->default(StaffStatus::Active->value);
            $table->date('hired_at')->nullable();
            $table->date('terminated_at')->nullable();
            $table->jsonb('permissions')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'home_branch_id'])
                ->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'user_id']);
            $table->unique(['gym_id', 'employee_number']);
            $table->index(['gym_id', 'status', 'created_at']);
            $table->index(['gym_id', 'home_branch_id', 'status']);
        });

        Schema::create('staff_profile_branch', function (Blueprint $table): void {
            $table->uuid('gym_id');
            $table->uuid('staff_profile_id');
            $table->uuid('branch_id');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'staff_profile_id'])
                ->references(['gym_id', 'id'])->on('staff_profiles')->cascadeOnDelete();
            $table->foreign(['gym_id', 'branch_id'])
                ->references(['gym_id', 'id'])->on('gym_branches')->cascadeOnDelete();
            $table->primary(['gym_id', 'staff_profile_id', 'branch_id']);
            $table->index(['gym_id', 'branch_id']);
        });

        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('home_branch_id')->nullable();
            $table->uuid('invited_by');
            $table->string('email', 254);
            $table->string('role', 40);
            $table->string('employee_number', 50);
            $table->string('job_title', 120)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 30)->default(InvitationStatus::Pending->value);
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'home_branch_id'])
                ->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'employee_number']);
            $table->index(['gym_id', 'email', 'status']);
            $table->index(['gym_id', 'status', 'expires_at']);
        });

        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('branch_id')->nullable();
            $table->string('name', 160);
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->string('billing_interval', 30);
            $table->unsignedSmallInteger('interval_count')->default(1);
            // Integer minor units prevent rounding errors in every supported currency.
            $table->unsignedBigInteger('price_amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('joining_fee_minor')->default(0);
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->string('status', 30)->default(PlanStatus::Active->value);
            $table->jsonb('terms')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign(['gym_id', 'branch_id'])
                ->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->unique(['gym_id', 'code']);
            $table->index(['gym_id', 'status', 'name']);
            $table->index(['gym_id', 'branch_id', 'status']);
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('gym_id');
            $table->uuid('member_id');
            $table->uuid('plan_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('created_by');
            $table->string('status', 30);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->date('next_billing_at')->nullable();
            // Plan terms are copied here so later edits cannot rewrite a contract.
            $table->unsignedBigInteger('price_amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('joining_fee_minor')->default(0);
            $table->string('billing_interval', 30);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->boolean('auto_renew')->default(true);
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->jsonb('terms_snapshot')->nullable();
            $table->timestampsTz();

            $table->foreign('gym_id')->references('id')->on('gyms')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['gym_id', 'member_id'])
                ->references(['gym_id', 'id'])->on('members')->restrictOnDelete();
            $table->foreign(['gym_id', 'plan_id'])
                ->references(['gym_id', 'id'])->on('membership_plans')->restrictOnDelete();
            $table->foreign(['gym_id', 'branch_id'])
                ->references(['gym_id', 'id'])->on('gym_branches')->restrictOnDelete();
            $table->unique(['gym_id', 'id']);
            $table->index(['gym_id', 'member_id', 'status']);
            $table->index(['gym_id', 'status', 'next_billing_at']);
            $table->index(['gym_id', 'plan_id', 'status']);
            $table->index(['gym_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
        Schema::dropIfExists('staff_invitations');
        Schema::dropIfExists('staff_profile_branch');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('members');
        Schema::dropIfExists('gym_branches');
    }
};
