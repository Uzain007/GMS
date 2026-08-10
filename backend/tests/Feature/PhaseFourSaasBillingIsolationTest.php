<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\GymSubscription;
use App\Models\PlatformBillingCustomer;
use App\Models\SaasPlan;
use App\Models\SaasPlanPrice;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseFourSaasBillingIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_reads_fail_closed_across_gyms_and_manager_cannot_checkout(): void
    {
        [$owner, $allowedGym] = $this->tenant(UserRole::GymOwner);
        [, $blockedGym] = $this->tenant(UserRole::GymOwner);
        $plan = SaasPlan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'status' => 'active',
            'feature_limits' => ['members' => 2500, 'branches' => 3, 'staff' => 30, 'advanced_reports' => true, 'priority_support' => false],
        ]);
        $price = SaasPlanPrice::query()->create([
            'saas_plan_id' => $plan->id, 'currency' => Currency::GBP,
            'billing_interval' => 'monthly', 'amount_minor' => 7900,
            'trial_days' => 14, 'active' => true,
        ]);

        app(TenantContext::class)->run($blockedGym, function () use ($blockedGym, $plan, $price): void {
            $customer = PlatformBillingCustomer::query()->create([
                'provider_customer_id' => 'cus_blocked', 'billing_email' => 'owner@blocked.test',
                'country_code' => 'GB', 'default_currency' => Currency::GBP,
            ]);
            GymSubscription::query()->create([
                'billing_customer_id' => $customer->id, 'saas_plan_id' => $plan->id,
                'saas_plan_price_id' => $price->id, 'provider_subscription_id' => 'sub_blocked',
                'status' => 'active', 'plan_code_snapshot' => $plan->code,
                'plan_name_snapshot' => $plan->name, 'feature_limits_snapshot' => $plan->feature_limits,
                'currency' => Currency::GBP, 'amount_minor' => 7900, 'billing_interval' => 'monthly',
            ]);
        });

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/gyms/{$allowedGym->id}/saas-subscription", [
            'X-Gym-ID' => $allowedGym->id,
        ])->assertSuccessful()->assertJsonPath('data', null);

        $manager = User::factory()->create();
        $this->attachRole($allowedGym, $manager, UserRole::GymManager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/gyms/{$allowedGym->id}/saas-subscription/checkout", [
            'saas_plan_price_id' => $price->id,
            'idempotency_key' => 'manager-must-not-checkout-001',
        ], ['X-Gym-ID' => $allowedGym->id])->assertForbidden();
    }

    /** @return array{User, Gym} */
    private function tenant(UserRole $role): array
    {
        $user = User::factory()->create();
        $gym = Gym::factory()->create(['base_currency' => Currency::GBP]);
        $this->attachRole($gym, $user, $role);
        return [$user, $gym];
    }

    private function attachRole(Gym $gym, User $user, UserRole $role): void
    {
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($user, [
            'role' => $role->value,
            'status' => 'active',
        ]));
    }
}
