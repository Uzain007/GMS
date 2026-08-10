<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\Member;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseSixReportingIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_counts_only_the_selected_tenant_and_rejects_cross_tenant_header(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [, $otherGym] = $this->tenant(UserRole::GymOwner);
        $this->member($gym, 'ONE');
        $this->member($otherGym, 'TWO');
        $today = today($gym->timezone)->toDateString();

        Sanctum::actingAs($owner);
        $this->getJson(
            "/api/v1/gyms/{$gym->id}/reports/overview?from={$today}&to={$today}&currency=GBP",
            ['X-Gym-ID' => $gym->id],
        )
            ->assertOk()
            ->assertJsonPath('data.period.days', 1)
            ->assertJsonPath('data.period.currency', 'GBP')
            ->assertJsonPath('data.summary.active_members', 1)
            ->assertJsonPath('data.summary.new_members', 1)
            ->assertJsonCount(1, 'data.daily');

        $this->getJson(
            "/api/v1/gyms/{$gym->id}/reports/overview?from={$today}&to={$today}&currency=GBP",
            ['X-Gym-ID' => $otherGym->id],
        )->assertUnprocessable();
    }

    public function test_report_requires_management_role_and_caps_the_date_window(): void
    {
        [$receptionist, $gym] = $this->tenant(UserRole::Receptionist);
        Sanctum::actingAs($receptionist);

        $this->getJson(
            "/api/v1/gyms/{$gym->id}/reports/overview?from=2025-01-01&to=2026-01-02&currency=GBP",
            ['X-Gym-ID' => $gym->id],
        )->assertForbidden();

        [$owner, $ownerGym] = $this->tenant(UserRole::GymOwner);
        Sanctum::actingAs($owner);
        $this->getJson(
            "/api/v1/gyms/{$ownerGym->id}/reports/overview?from=2025-01-01&to=2026-01-02&currency=GBP",
            ['X-Gym-ID' => $ownerGym->id],
        )->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    /** @return array{User, Gym} */
    private function tenant(UserRole $role): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create(['base_currency' => Currency::GBP]);
        app(TenantContext::class)->run($gym, function () use ($gym, $user, $role): void {
            $gym->users()->attach($user, ['role' => $role->value, 'status' => 'active']);
        });

        return [$user, $gym];
    }

    private function member(Gym $gym, string $suffix): Member
    {
        return app(TenantContext::class)->run($gym, fn () => Member::query()->create([
            'member_number' => "MBR-{$suffix}",
            'first_name' => 'Member',
            'last_name' => $suffix,
            'status' => MemberStatus::Active,
            'joined_at' => now(),
        ]));
    }
}
