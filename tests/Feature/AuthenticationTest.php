<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_the_current_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Operadora',
            'email' => 'Ana@Nuvora.test',
            'password' => 'Password12345',
            'password_confirmation' => 'Password12345',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ana@nuvora.test')
            ->assertJsonMissingPath('data.user.password');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'ana@nuvora.test',
        ]);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'ana@nuvora.test');
    }

    public function test_register_rejects_a_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Operadora',
            'email' => 'ana@nuvora.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ana@nuvora.test']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Operadora',
            'email' => 'ana@nuvora.test',
            'password' => 'Password12345',
            'password_confirmation' => 'Password12345',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'ana@nuvora.test',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ANA@nuvora.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonMissingPath('data.user.password');

        $this->assertAuthenticatedAs($user);

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertGuest();

        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_login_rejects_invalid_credentials_without_revealing_the_account(): void
    {
        User::factory()->create(['email' => 'ana@nuvora.test']);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@nuvora.test',
            'password' => 'password',
        ]);

        $known = $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@nuvora.test',
            'password' => 'wrong-password',
        ]);

        $unknown->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $known->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertSame($unknown->json('error.message'), $known->json('error.message'));
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'ana@nuvora.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ana@nuvora.test',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@nuvora.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests()
            ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_unknown_api_route_uses_the_error_contract(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonStructure([
                'success',
                'error' => ['code', 'message', 'details'],
                'request_id',
            ]);
    }
}
