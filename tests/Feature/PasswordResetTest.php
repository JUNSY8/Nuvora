<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Persistence\AuditLog;
use App\Modules\Identity\Infrastructure\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_does_not_reveal_whether_the_account_exists(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'ana@nuvora.test']);

        $known = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ana@nuvora.test',
        ]);

        $unknown = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@nuvora.test',
        ]);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('data.message'), $unknown->json('data.message'));

        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_a_valid_token_replaces_the_password_without_storing_it_in_the_audit_log(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'ana@nuvora.test']);
        $token = null;

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ANA@nuvora.test',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'ana@nuvora.test',
            'token' => $token,
            'password' => 'NuevaClave1234',
            'password_confirmation' => 'NuevaClave1234',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NuevaClave1234', $user->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@nuvora.test',
            'password' => 'password',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@nuvora.test',
            'password' => 'NuevaClave1234',
        ])->assertOk();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'auth.password_reset_completed',
            'resource_id' => 'NuevaClave1234',
        ]);

        $audit = AuditLog::query()
            ->where('action', 'auth.password_reset_completed')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('NuevaClave1234', json_encode($audit->properties));
    }

    public function test_an_invalid_reset_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'ana@nuvora.test']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'ana@nuvora.test',
            'token' => 'not-a-real-token',
            'password' => 'NuevaClave1234',
            'password_confirmation' => 'NuevaClave1234',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
}
