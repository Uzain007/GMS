<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gym_user_cannot_access_another_gym(): void
    {
        $user = User::factory()->create();
        $allowed = Gym::factory()->create();
        $blocked = Gym::factory()->create();
        // PostgreSQL gym_user RLS requires the exact tenant on every pivot write.
        app(TenantContext::class)->run($allowed, fn () => $allowed->users()->attach($user, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
        ]));
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/gyms/{$blocked->id}", ['X-Gym-ID' => $blocked->id])->assertForbidden();
        $this->getJson("/api/v1/gyms/{$allowed->id}", ['X-Gym-ID' => $allowed->id])->assertOk();
    }

    public function test_super_admin_can_access_any_gym(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $gym = Gym::factory()->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/gyms/{$gym->id}", ['X-Gym-ID' => $gym->id])->assertOk();
    }

    public function test_route_and_header_must_select_the_same_gym(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        $routeGym = Gym::factory()->create();
        $headerGym = Gym::factory()->create();
        Sanctum::actingAs($admin);

        // Even a platform administrator cannot silently cross the explicit
        // tenant boundary by sending a different gym in the request header.
        $this->getJson("/api/v1/gyms/{$routeGym->id}", ['X-Gym-ID' => $headerGym->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The gym context does not match the request route.');
    }
}
