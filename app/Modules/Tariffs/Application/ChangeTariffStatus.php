<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Infrastructure\Persistence\Tariff;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Activating or deactivating is a version too, so the tariffs in force at
 * any past moment can be rebuilt. Tariffs are never deleted.
 */
final class ChangeTariffStatus
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TariffLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $tariffId, TariffStatus $target, ?string $note): Tariff
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_MANAGE);

        [$tariff, $from] = DB::transaction(function () use ($actorUserId, $tenantId, $tariffId, $target, $note): array {
            $tariff = $this->lookup->tariff($tenantId, $tariffId, lock: true);
            $from = $tariff->status;

            if ($from === $target) {
                return [$tariff, null];
            }

            UpdateTariff::publish($tariff, UpdateTariff::current($tariff), ['status' => $target], $actorUserId, $note);

            return [$tariff, $from];
        });

        /** @var Tariff $tariff */
        if ($from instanceof TariffStatus) {
            $this->audit->record(
                action: 'tariff.status_changed',
                resourceType: 'tariff',
                resourceId: (string) $tariff->id,
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                properties: ['code' => $tariff->code, 'from' => $from->value, 'to' => $target->value, 'version' => $tariff->current_version],
            );
        }

        return $tariff;
    }
}
