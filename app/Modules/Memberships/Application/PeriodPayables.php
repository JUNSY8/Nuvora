<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Customers\Application\CustomerDirectory;
use App\Modules\Memberships\Infrastructure\Persistence\Membership;
use App\Modules\Memberships\Infrastructure\Persistence\MembershipPeriod;
use App\Modules\Payments\Application\Payable;
use App\Modules\Payments\Application\PayableSource;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentBalance;

/**
 * Lets Payments collect a membership period. A period is "due" until it is
 * paid in full and goes back to "due" if a refund leaves it short. Coverage
 * does not depend on it: the membership covers its days paid or not, and
 * the grace days are the margin to collect.
 */
final class PeriodPayables implements PayableSource
{
    public function __construct(private readonly CustomerDirectory $customers) {}

    public function type(): PayableType
    {
        return PayableType::MembershipPeriod;
    }

    public function lock(string $tenantId, string $payableId): Payable
    {
        $period = MembershipPeriod::query()->where('tenant_id', $tenantId)->whereKey($payableId)->lockForUpdate()->firstOrFail();
        $membership = Membership::query()->where('tenant_id', $tenantId)->whereKey($period->membership_id)->with('plan:id,name')->firstOrFail();
        $customer = $this->customers->summary($tenantId, (string) $membership->customer_id);

        return new Payable(
            type: PayableType::MembershipPeriod,
            id: (string) $period->id,
            amountDue: $period->amount,
            currency: $period->currency,
            lotId: $membership->assigned_lot_id === null ? null : (string) $membership->assigned_lot_id,
            customerId: (string) $membership->customer_id,
            description: "Mensualidad {$membership->code} · {$membership->plan->name} · periodo {$period->sequence}",
            plate: null,
            details: [
                'kind' => 'membership_period',
                'membership_id' => (string) $membership->id,
                'membership_code' => $membership->code,
                'plan' => $membership->plan->name,
                'customer' => $customer['name'] ?? null,
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
            ],
            notPayableReason: $period->status === 'void' ? 'El periodo fue anulado y no tiene cobro.' : null,
        );
    }

    public function settled(string $tenantId, string $payableId, PaymentBalance $balance): void
    {
        MembershipPeriod::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($payableId)
            ->where('status', '!=', 'void')
            ->update(['status' => $balance->isSettled() ? 'paid' : 'due']);
    }
}
