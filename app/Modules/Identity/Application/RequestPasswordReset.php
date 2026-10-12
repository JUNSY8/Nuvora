<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Identity\Domain\PasswordResetThrottled;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Support\Facades\Password;

final class RequestPasswordReset
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_THROTTLED) {
            throw new PasswordResetThrottled;
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $this->audit->record(
            action: 'auth.password_reset_requested',
            resourceType: 'user',
            resourceId: (string) $user->id,
            tenantId: null,
            actorUserId: (string) $user->id,
        );
    }
}
