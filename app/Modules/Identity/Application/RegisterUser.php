<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Support\Facades\Log;

final class RegisterUser
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(string $name, string $email, string $password): User
    {
        $user = User::query()->create([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ]);

        $user->sendEmailVerificationNotification();

        Log::info('user_registered', [
            'user_id' => $user->id,
        ]);

        $this->audit->record(
            action: 'user.registered',
            resourceType: 'user',
            resourceId: (string) $user->id,
            tenantId: null,
            actorUserId: (string) $user->id,
        );

        return $user;
    }
}
