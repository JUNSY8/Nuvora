<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Notifications\VerifyEmailAddress;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_a_verification_email_and_the_signed_link_verifies_the_account(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Operadora',
            'email' => 'ana@nuvora.test',
            'password' => 'Password12345',
            'password_confirmation' => 'Password12345',
        ])->assertCreated()
            ->assertJsonPath('data.user.email_verified_at', null);

        $user = User::query()->where('email', 'ana@nuvora.test')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmailAddress::class);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->getJson($url)->assertOk();
    }

    public function test_a_tampered_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson('/api/v1/auth/email/verify/'.$user->id.'/'.sha1($user->email))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_authenticated_user_can_request_another_verification_email(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk();

        Notification::assertSentTo($user, VerifyEmailAddress::class);
    }
}
