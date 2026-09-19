<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_returns_a_user_and_token_with_201(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Eman',
            'email' => 'eman@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Eman')
            ->assertJsonPath('data.user.email', 'eman@example.com')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);
        $this->assertDatabaseHas('users', ['email' => 'eman@example.com']);
    }

    public function test_registration_returns_422_for_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'eman@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Eman',
            'email' => 'eman@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonPath('error.details.email.0', 'The email has already been taken.');
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_returns_422_for_bad_credentials(): void
    {
        User::factory()->create(['email' => 'eman@example.com', 'password' => 'password']);

        $this->postJson('/api/login', ['email' => 'eman@example.com', 'password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.email.0', 'The provided credentials are incorrect.');
    }

    public function test_me_returns_only_the_authenticated_users_basic_information(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Authenticated user retrieved successfully.', 'data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]]);
    }

    public function test_me_returns_401_when_no_token_is_provided(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current')->plainTextToken;
        $otherToken = $user->createToken('other')->plainTextToken;

        $this->withToken($currentToken)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'current']);

        $this->app['auth']->forgetGuards();
        $this->withToken($currentToken)->getJson('/api/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/me')->assertOk();
    }
}
