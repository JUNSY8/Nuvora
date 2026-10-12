<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Auth\Access\AuthorizationException;

final class VerifyEmailAddress
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(string $userId, string $hash): User
    {
        $user = User::query()->findOrFail($userId);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new AuthorizationException;
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            $this->audit->record(
                action: 'auth.email_verified',
                resourceType: 'user',
                resourceId: (string) $user->id,
                tenantId: null,
                actorUserId: (string) $user->id,
            );
        }

        return $user;
    }
}
