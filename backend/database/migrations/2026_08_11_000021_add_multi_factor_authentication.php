<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // MFA belongs to the platform identity, never to a tenant. Laravel's
            // encrypted cast protects the secret while the replay counter stays
            // queryable for a row-locked monotonic comparison.
            $table->text('mfa_secret')->nullable();
            $table->timestampTz('mfa_confirmed_at')->nullable();
            $table->unsignedBigInteger('mfa_last_used_step')->nullable();
        });

        Schema::create('user_mfa_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Only an application-keyed digest is retained. The 80-bit plaintext
            // is returned once and never becomes a loggable database value.
            $table->char('code_hash', 64);
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'code_hash']);
            $table->index(['user_id', 'used_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP POLICY IF EXISTS ironcore_platform_audit_insert ON audit_logs');
            DB::unprepared(<<<'SQL'
                CREATE POLICY ironcore_platform_audit_insert
                ON audit_logs FOR INSERT
                WITH CHECK (
                    gym_id IS NULL
                    AND actor_id = nullif(current_setting('ironcore.current_user_id', true), '')::uuid
                    AND (
                        EXISTS (
                            SELECT 1 FROM users
                            WHERE users.id = actor_id AND users.platform_role = 'super_admin'
                        )
                        OR (
                            auditable_type = 'App\Models\User'
                            AND auditable_id = actor_id
                            AND event IN (
                                'account.mfa.enabled',
                                'account.mfa.recovery_codes_regenerated',
                                'account.mfa.disabled'
                            )
                        )
                    )
                )
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP POLICY IF EXISTS ironcore_platform_audit_insert ON audit_logs');
            DB::unprepared(<<<'SQL'
                CREATE POLICY ironcore_platform_audit_insert
                ON audit_logs FOR INSERT
                WITH CHECK (
                    gym_id IS NULL
                    AND actor_id = nullif(current_setting('ironcore.current_user_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM users
                        WHERE users.id = actor_id AND users.platform_role = 'super_admin'
                    )
                )
            SQL);
        }

        Schema::dropIfExists('user_mfa_recovery_codes');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_last_used_step']);
        });
    }
};
