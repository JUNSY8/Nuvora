<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Infrastructure\Persistence\Discount;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

/**
 * Discounts are deactivated, never deleted: closed sessions point to them.
 */
final class ChangeDiscountStatus
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TariffLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, string $discountId, TariffStatus $target): Discount
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_MANAGE);

        $discount = $this->lookup->discount($tenantId, $discountId);
        $from = $discount->status;

        if ($from === $target) {
            return $discount;
        }

        $discount->fill(['status' => $target])->save();

        $this->audit->record(
            action: 'discount.status_changed',
            resourceType: 'discount',
            resourceId: (string) $discount->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: ['code' => $discount->code, 'from' => $from->value, 'to' => $target->value],
        );

        return $discount;
    }
}
