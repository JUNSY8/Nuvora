<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetUserPassword
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(string $email, string $password, string $passwordConfirmation, string $token): void
    {
        $status = Password::reset(
            [
                'email' => mb_strtolower(trim($email)),
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
                'token' => $token,
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                DB::table('sessions')->where('user_id', $user->id)->delete();

                $this->audit->record(
                    action: 'auth.password_reset_completed',
                    resourceType: 'user',
                    resourceId: (string) $user->id,
                    tenantId: null,
                    actorUserId: (string) $user->id,
                );
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'El enlace de restablecimiento no es válido o expiró.',
            ]);
        }
    }
}
