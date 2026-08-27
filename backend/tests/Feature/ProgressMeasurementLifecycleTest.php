<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\ProgressMeasurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\Member;
use App\Models\MemberProgressMeasurement;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgressMeasurementLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_can_create_correct_view_and_void_without_erasing_history(): void
    {
        [$owner, $gym] = $this->tenant();
        $member = $this->member($gym, 'PRIMARY');
        Sanctum::actingAs($owner);

        $created = $this->postJson("/api/v1/gyms/{$gym->id}/progress-measurements", [
            'member_id' => $member->id,
            'metric' => 'body_weight',
            'value_milli' => 82450,
            'unit' => 'kg',
            'measured_at' => now()->subHour()->toIso8601String(),
            'note' => 'Reception baseline',
        ], ['X-Gym-ID' => $gym->id])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data');

        $this->patchJson("/api/v1/gyms/{$gym->id}/progress-measurements/{$created['id']}", [
            'metric' => 'body_weight', 'value_milli' => 82100, 'unit' => 'kg',
            'measured_at' => now()->subMinutes(30)->toIso8601String(),
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $corrected = $this->patchJson("/api/v1/gyms/{$gym->id}/progress-measurements/{$created['id']}", [
            'metric' => 'body_weight',
            'value_milli' => 82100,
            'unit' => 'kg',
            'measured_at' => now()->subMinutes(30)->toIso8601String(),
            'note' => 'Scale reading confirmed',
            'reason' => 'Initial entry used the wrong scale reading',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.replaces_measurement_id', $created['id'])
            ->assertJsonPath('data.value_milli', 82100)
            ->json('data');

        app(TenantContext::class)->run($gym, function () use ($created, $corrected): void {
            $original = MemberProgressMeasurement::query()->findOrFail($created['id']);
            $replacement = MemberProgressMeasurement::query()->findOrFail($corrected['id']);
            $this->assertSame(82450, $original->value_milli);
            $this->assertSame('Reception baseline', $original->note);
            $this->assertSame(ProgressMeasurementStatus::Corrected, $original->status);
            $this->assertSame(ProgressMeasurementStatus::Active, $replacement->status);
        });

        $this->getJson("/api/v1/gyms/{$gym->id}/progress-measurements", ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $corrected['id']);
        $this->getJson("/api/v1/gyms/{$gym->id}/progress-measurements?include_history=1", ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()->assertJsonCount(2, 'data');
        $this->patchJson("/api/v1/gyms/{$gym->id}/progress-measurements/{$created['id']}", [
            'metric' => 'body_weight', 'value_milli' => 82000, 'unit' => 'kg',
            'measured_at' => now()->subMinutes(20)->toIso8601String(), 'reason' => 'Second correction attempt',
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()->assertJsonValidationErrors('measurement');

        $this->deleteJson("/api/v1/gyms/{$gym->id}/progress-measurements/{$corrected['id']}", [
            'reason' => 'Duplicate measurement confirmed by gym manager',
        ], ['X-Gym-ID' => $gym->id])->assertSuccessful()->assertJsonPath('data.status', 'voided');

        $this->getJson("/api/v1/gyms/{$gym->id}/progress-measurements", ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/gyms/{$gym->id}/progress-measurements?include_history=1", ['X-Gym-ID' => $gym->id])
            ->assertSuccessful()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/v1/gyms/{$gym->id}/progress-measurements/{$corrected['id']}", [
            'reason' => 'Second deletion attempt',
        ], ['X-Gym-ID' => $gym->id])->assertUnprocessable()->assertJsonValidationErrors('measurement');
        app(TenantContext::class)->run($gym, function () use ($created, $gym): void {
            // Assertions must cross the same forced-RLS tenant boundary as the
            // application instead of relying on SQLite's unrestricted reads.
            $this->assertDatabaseCount('member_progress_measurements', 2);
            $correctionAudit = AuditLog::query()->where('gym_id', $gym->id)->where('event', 'progress_measurement.corrected')->where('reason', 'Initial entry used the wrong scale reading')->firstOrFail();
            $this->assertSame($created['id'], $correctionAudit->before_values['id']);
            $this->assertSame('active', $correctionAudit->before_values['status']);
            $voidAudit = AuditLog::query()->where('gym_id', $gym->id)->where('event', 'progress_measurement.voided')->where('reason', 'Duplicate measurement confirmed by gym manager')->firstOrFail();
            $this->assertSame('active', $voidAudit->before_values['status']);
            $this->assertSame('voided', $voidAudit->after_values['status']);
        });
    }

    public function test_measurement_changes_are_management_only_and_cross_tenant_ids_are_hidden(): void
    {
        [$owner, $gym] = $this->tenant();
        [$otherOwner, $otherGym] = $this->tenant();
        $this->member($gym, 'LOCAL');
        $otherMember = $this->member($otherGym, 'OTHER');

        Sanctum::actingAs($otherOwner);
        $otherMeasurement = $this->postJson("/api/v1/gyms/{$otherGym->id}/progress-measurements", [
            'member_id' => $otherMember->id, 'metric' => 'waist', 'value_milli' => 79000,
            'unit' => 'cm', 'measured_at' => now()->subMinute()->toIso8601String(),
        ], ['X-Gym-ID' => $otherGym->id])->assertCreated()->json('data');

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/gyms/{$gym->id}/progress-measurements/{$otherMeasurement['id']}", [
            'metric' => 'waist', 'value_milli' => 78000, 'unit' => 'cm',
            'measured_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Attempted correction',
        ], ['X-Gym-ID' => $gym->id])->assertNotFound();
        $this->deleteJson("/api/v1/gyms/{$gym->id}/progress-measurements/{$otherMeasurement['id']}", [
            'reason' => 'Attempted deletion',
        ], ['X-Gym-ID' => $gym->id])->assertNotFound();

        $trainer = User::factory()->create();
        app(TenantContext::class)->run($otherGym, fn () => $otherGym->users()->attach($trainer, [
            'role' => UserRole::Trainer->value, 'status' => 'active',
        ]));
        Sanctum::actingAs($trainer);
        $this->patchJson("/api/v1/gyms/{$otherGym->id}/progress-measurements/{$otherMeasurement['id']}", [
            'metric' => 'waist', 'value_milli' => 78000, 'unit' => 'cm',
            'measured_at' => now()->subMinute()->toIso8601String(), 'reason' => 'Trainer attempt',
        ], ['X-Gym-ID' => $otherGym->id])->assertForbidden();
        $this->deleteJson("/api/v1/gyms/{$otherGym->id}/progress-measurements/{$otherMeasurement['id']}", [
            'reason' => 'Trainer attempt',
        ], ['X-Gym-ID' => $otherGym->id])->assertForbidden();
    }

    /** @return array{User, Gym} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner, [
            'role' => UserRole::GymOwner->value, 'status' => 'active',
        ]));

        return [$owner, $gym];
    }

    private function member(Gym $gym, string $suffix): Member
    {
        return app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'member_number' => 'MBR-'.$suffix,
            'first_name' => 'Progress',
            'last_name' => $suffix,
            'email' => strtolower($suffix).'@example.test',
            'status' => MemberStatus::Active,
        ]));
    }
}
