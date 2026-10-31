<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * An offline refund completes at once. An online one may wait for the
 * provider, and a failed one gives its amount back to the payment.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
