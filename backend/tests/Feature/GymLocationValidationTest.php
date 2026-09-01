<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gym;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GymLocationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_and_edit_a_gym_with_canonical_location_values(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/gyms', [
            'name' => 'Karachi Strength Club',
            'legal_name' => 'Karachi Strength Club Limited',
            'base_currency' => 'PKR',
            'country_code' => 'pk',
            'timezone' => 'Asia/Karachi',
            'owner' => [
                'name' => 'Gym Owner',
                'email' => 'karachi-owner@example.test',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.country_code', 'PK')
            ->assertJsonPath('data.timezone', 'Asia/Karachi');

        $gymId = $created->json('data.id');

        $this->patchJson("/api/v1/gyms/{$gymId}", [
            'country_code' => 'ae',
            'timezone' => 'Asia/Dubai',
            'reason' => 'Moving the registered operating location',
        ], ['X-Gym-ID' => $gymId])
            ->assertOk()
            ->assertJsonPath('data.country_code', 'AE')
            ->assertJsonPath('data.timezone', 'Asia/Dubai');

        $this->assertDatabaseHas('gyms', [
            'id' => $gymId,
            'country_code' => 'AE',
            'timezone' => 'Asia/Dubai',
        ]);
    }

    public function test_create_and_edit_reject_unknown_countries_and_free_text_timezones(): void
    {
        $admin = User::factory()->create(['platform_role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/gyms', [
            'name' => 'Invalid Location Gym',
            'base_currency' => 'GBP',
            'country_code' => 'XX',
            'timezone' => 'GMT +5',
            'owner' => [
                'name' => 'Invalid Owner',
                'email' => 'invalid-location-owner@example.test',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['country_code', 'timezone']);

        $created = $this->postJson('/api/v1/gyms', [
            'name' => 'Valid Location Gym',
            'base_currency' => 'GBP',
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'owner' => [
                'name' => 'Valid Owner',
                'email' => 'valid-location-owner@example.test',
            ],
        ])->assertCreated();

        $gymId = $created->json('data.id');

        $this->patchJson("/api/v1/gyms/{$gymId}", [
            'country_code' => 'XX',
            'timezone' => 'GMT +5',
            'reason' => 'Trying invalid free text values',
        ], ['X-Gym-ID' => $gymId])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country_code', 'timezone']);

        $this->assertDatabaseHas('gyms', [
            'id' => $gymId,
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
        ]);
    }

    public function test_gym_admin_can_create_and_edit_a_branch_with_only_valid_iana_timezones(): void
    {
        $owner = User::factory()->create();
        $gym = Gym::factory()->create(['timezone' => 'Europe/London']);
        app(TenantContext::class)->run($gym, fn () => $gym->users()->attach($owner, [
            'role' => UserRole::GymOwner->value,
            'status' => 'active',
        ]));
        Sanctum::actingAs($owner);
        $headers = ['X-Gym-ID' => $gym->id];

        $created = $this->postJson("/api/v1/gyms/{$gym->id}/branches", [
            'name' => 'Karachi Branch',
            'code' => 'KHI-01',
            'timezone' => 'Asia/Karachi',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.timezone', 'Asia/Karachi');

        $branchId = $created->json('data.id');
        $this->patchJson("/api/v1/gyms/{$gym->id}/branches/{$branchId}", [
            'timezone' => 'Asia/Dubai',
            'reason' => 'Move branch operations to Dubai time',
        ], $headers)->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Dubai');

        $this->patchJson("/api/v1/gyms/{$gym->id}/branches/{$branchId}", [
            'timezone' => 'GMT +5',
            'reason' => 'Attempt invalid free text timezone',
        ], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');

        $this->assertDatabaseHas('gym_branches', [
            'id' => $branchId,
            'gym_id' => $gym->id,
            'timezone' => 'Asia/Dubai',
        ]);
    }
}
