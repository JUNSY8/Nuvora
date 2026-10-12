<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\AuditContext;
use App\Modules\Audit\Infrastructure\Persistence\AuditLog;

final class RecordAuditEvent
{
    public function __construct(private readonly AuditContext $context) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $action,
        string $resourceType,
        ?string $resourceId,
        ?string $tenantId,
        ?string $actorUserId,
        array $properties = [],
    ): void {
        $properties = $this->redact($properties);

        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ip_address' => $this->context->ipAddress,
            'user_agent' => $this->context->userAgent,
            'properties' => $properties === [] ? null : $properties,
            'request_id' => $this->context->requestId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function redact(array $properties): array
    {
        $hidden = ['password', 'password_confirmation', 'token', 'remember_token', 'current_password'];
        $redacted = [];

        foreach ($properties as $key => $value) {
            if (in_array(strtolower((string) $key), $hidden, true)) {
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }
}
