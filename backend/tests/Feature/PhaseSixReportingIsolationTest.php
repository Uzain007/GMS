<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\MemberStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Gym;
use App\Models\GymBranch;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Payment;
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

    public function test_branch_filter_scopes_every_report_section_and_rejects_another_gyms_branch(): void
    {
        [$owner, $gym] = $this->tenant(UserRole::GymOwner);
        [, $otherGym] = $this->tenant(UserRole::GymOwner);
        $today = today($gym->timezone)->toDateString();
        [$first, $second] = app(TenantContext::class)->run($gym, function () {
            return [
                GymBranch::query()->create(['name' => 'Central', 'code' => 'CENTRAL', 'status' => 'active', 'is_primary' => true]),
                GymBranch::query()->create(['name' => 'North', 'code' => 'NORTH', 'status' => 'active', 'is_primary' => false]),
            ];
        });
        $foreign = app(TenantContext::class)->run($otherGym, fn () => GymBranch::query()->create([
            'name' => 'Foreign', 'code' => 'FOREIGN', 'status' => 'active', 'is_primary' => true,
        ]));

        app(TenantContext::class)->run($gym, function () use ($gym, $owner, $first, $second): void {
            foreach ([$first, $second] as $index => $branch) {
                $member = Member::query()->create([
                    'home_branch_id' => $branch->id, 'member_number' => 'MBR-REPORT-'.$index,
                    'first_name' => 'Report', 'last_name' => (string) $index,
                    'status' => MemberStatus::Active, 'joined_at' => now(),
                ]);
                Payment::query()->create([
                    'member_id' => $member->id, 'branch_id' => $branch->id, 'recorded_by' => $owner->id,
                    'receipt_number' => 'RPT-'.$index, 'method' => 'cash', 'status' => PaymentStatus::Paid,
                    'amount_minor' => $index === 0 ? 1200 : 3400, 'currency' => Currency::GBP,
                    'paid_at' => now(),
                ]);
                Invoice::query()->create([
                    'member_id' => $member->id, 'branch_id' => $branch->id, 'created_by' => $owner->id,
                    'number' => 'RPT-INV-'.$index, 'status' => 'open', 'currency' => Currency::GBP,
                    'subtotal_amount_minor' => 500, 'total_amount_minor' => 500,
                    'due_amount_minor' => 500, 'issued_at' => now(),
                ]);
                ClassSession::query()->create([
                    'branch_id' => $branch->id, 'created_by' => $owner->id,
                    'title' => 'Report Class '.$index, 'starts_at' => now()->addHours(2),
                    'ends_at' => now()->addHours(3), 'capacity' => 10,
                    'booked_count' => $index === 0 ? 4 : 8,
                    'attended_count' => $index === 0 ? 2 : 6,
                ]);
            }
        });

        Sanctum::actingAs($owner);
        $url = "/api/v1/gyms/{$gym->id}/reports/overview?from={$today}&to={$today}&currency=GBP";
        $this->getJson($url, ['X-Gym-ID' => $gym->id])
            ->assertOk()
            ->assertJsonPath('data.summary.active_members', 2)
            ->assertJsonPath('data.summary.net_revenue_minor', 4600)
            ->assertJsonPath('data.summary.outstanding_minor', 1000)
            ->assertJsonPath('data.class_performance.sessions', 2);
        $this->getJson($url.'&branch_id='.$first->id, ['X-Gym-ID' => $gym->id])
            ->assertOk()
            ->assertJsonPath('data.period.branch_id', $first->id)
            ->assertJsonPath('data.summary.active_members', 1)
            ->assertJsonPath('data.summary.new_members', 1)
            ->assertJsonPath('data.summary.net_revenue_minor', 1200)
            ->assertJsonPath('data.summary.outstanding_minor', 500)
            ->assertJsonPath('data.class_performance.sessions', 1)
            ->assertJsonPath('data.class_performance.booked', 4)
            ->assertJsonPath('data.daily.0.net_revenue_minor', 1200)
            ->assertJsonPath('data.member_status.0.count', 1)
            ->assertJsonPath('data.payment_methods.0.count', 1)
            ->assertJsonPath('data.payment_methods.0.net_minor', 1200);
        $this->getJson($url.'&branch_id='.$second->id, ['X-Gym-ID' => $gym->id])
            ->assertOk()
            ->assertJsonPath('data.summary.net_revenue_minor', 3400)
            ->assertJsonPath('data.class_performance.booked', 8);
        // The tenant-scoped validator must reject a valid UUID from another gym.
        $this->getJson($url.'&branch_id='.$foreign->id, ['X-Gym-ID' => $gym->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
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
