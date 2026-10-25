<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentBalance;

/**
 * Contract a module implements to let Payments collect what it charges.
 * Payments never reads the module's tables; the module never writes
 * payments. Both calls run inside the payment's transaction.
 */
interface PayableSource
{
    public function type(): PayableType;

    /**
     * Loads the payable of the tenant and locks it until the transaction
     * ends, so two payments of the same charge are serialized. Throws
     * ModelNotFoundException when the tenant has no such payable.
     */
    public function lock(string $tenantId, string $payableId): Payable;

    /**
     * Told after every payment or refund, with what the payable now owes.
     */
    public function settled(string $tenantId, string $payableId, PaymentBalance $balance): void;
}
