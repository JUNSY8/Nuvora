<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Parking\Application\ParkingInfrastructureDirectory;
use App\Modules\Payments\Infrastructure\Persistence\PaymentRefund;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Application\TenantDirectory;
use App\Modules\Tenancy\Domain\Permission;

/**
 * What a printed receipt shows. It is built from the payment's own snapshot
 * of the payable, so reprinting it later shows what was paid then. It is
 * not an electronic invoice (0.11) and does not break down taxes.
 */
final class PaymentReceipt
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantDirectory $tenants,
        private readonly ParkingInfrastructureDirectory $parking,
        private readonly PaymentLookup $lookup,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $actorUserId, string $tenantId, string $paymentId): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_VIEW);

        $payment = $this->lookup->find($tenantId, $paymentId)->load(['receivedBy:id,name', 'refunds.refundedBy:id,name']);
        $lot = $payment->parking_lot_id === null ? null : ($this->parking->lotSummaries($tenantId, [(string) $payment->parking_lot_id])[(string) $payment->parking_lot_id] ?? null);

        return [
            'number' => $payment->number,
            'issued_at' => $payment->received_at->toIso8601String(),
            'timezone' => $this->tenants->timezone($tenantId),
            'tenant' => ['name' => $this->tenants->name($tenantId)],
            'parking_lot' => $lot,
            'description' => $payment->description,
            'plate' => $payment->plate,
            'details' => $payment->details,
            'method' => $payment->method->value,
            'method_label' => $payment->method->label(),
            'status' => $payment->status->value,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'tendered' => $payment->tendered_amount,
            'change' => $payment->change_amount,
            'reference' => $payment->reference,
            'refunded' => $payment->refunded_amount,
            'cashier' => $payment->receivedBy?->name,
            'refunds' => $payment->refunds->map(fn (PaymentRefund $refund): array => [
                'number' => $refund->number,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'refunded_at' => $refund->refunded_at->toIso8601String(),
                'refunded_by' => $refund->refundedBy?->name,
            ])->values()->all(),
        ];
    }
}
