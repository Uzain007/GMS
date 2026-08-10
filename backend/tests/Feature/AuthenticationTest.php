<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_start_a_stateful_web_session_without_exposing_a_token(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'use_bearer_token' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.authentication', 'session')
            ->assertJsonMissingPath('data.token');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_native_clients_can_explicitly_request_a_scoped_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-suite',
            'use_bearer_token' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.authentication', 'bearer')
            ->assertJsonPath('data.token_type', 'Bearer');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'test-suite']);
    }

    public function test_invalid_credentials_do_not_reveal_the_account_state(): void
    {
        User::factory()->create(['email' => 'member@example.com']);
        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])->postJson('/api/v1/auth/login', ['email' => 'member@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
