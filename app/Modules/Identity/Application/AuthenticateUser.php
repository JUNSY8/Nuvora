<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class AuthenticateUser
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(string $email, string $password, bool $remember = false): bool
    {
        $authenticated = Auth::attempt([
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ], $remember);

        if (! $authenticated) {
            Log::info('login_failed');

            $this->audit->record(
                action: 'auth.login_failed',
                resourceType: 'user',
                resourceId: null,
                tenantId: null,
                actorUserId: null,
            );

            return false;
        }

        $userId = Auth::id();
        $userId = is_string($userId) ? $userId : null;

        Log::info('login_succeeded', [
            'user_id' => $userId,
        ]);

        $this->audit->record(
            action: 'auth.login_succeeded',
            resourceType: 'user',
            resourceId: $userId,
            tenantId: null,
            actorUserId: $userId,
        );

        return true;
    }
}
