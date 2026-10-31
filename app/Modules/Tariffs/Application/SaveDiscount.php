<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tariffs\Infrastructure\Persistence\Discount;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a discount of the catalog. Editing only affects charges
 * computed afterwards: a closed session keeps the discount's label and
 * amount in its own pricing record.
 */
final class SaveDiscount
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly DiscountAttributes $attributes,
        private readonly TariffLookup $lookup,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(string $actorUserId, string $tenantId, ?string $discountId, DiscountData $data): Discount
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::TARIFFS_MANAGE);

        $discount = $discountId === null ? null : $this->lookup->discount($tenantId, $discountId);
        $attributes = $this->attributes->from($tenantId, $data);

        $taken = Discount::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $attributes['code'])
            ->when($discount !== null, fn ($query) => $query->whereKeyNot($discount?->id))
            ->exists();

        if ($taken) {
            throw DiscountAttributes::codeTaken();
        }

        $before = $discount === null ? null : self::snapshot($discount);

        try {
            $discount = DB::transaction(function () use ($discount, $actorUserId, $tenantId, $attributes): Discount {
                if ($discount !== null) {
                    $discount->fill($attributes)->save();

                    return $discount;
                }

                return Discount::query()->create([
                    ...$attributes,
                    'tenant_id' => $tenantId,
                    'status' => TariffStatus::Active,
                    'created_by' => $actorUserId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw DiscountAttributes::codeTaken();
        }

        $this->audit->record(
            action: $before === null ? 'discount.created' : 'discount.updated',
            resourceType: 'discount',
            resourceId: (string) $discount->id,
            tenantId: $tenantId,
            actorUserId: $actorUserId,
            properties: $before === null ? self::snapshot($discount) : ['before' => $before, 'after' => self::snapshot($discount)],
        );

        return $discount;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Discount $discount): array
    {
        return [
            'code' => $discount->code,
            'name' => $discount->name,
            'type' => $discount->type->value,
            'value' => $discount->value,
            'currency' => $discount->currency,
            'application' => $discount->application->value,
            'parking_lot_id' => $discount->parking_lot_id,
            'vehicle_types' => $discount->vehicle_types,
            'days' => $discount->days,
            'valid_from' => $discount->valid_from,
            'valid_until' => $discount->valid_until,
            'status' => $discount->status->value,
        ];
    }
}
