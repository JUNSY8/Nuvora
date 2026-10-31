<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Gateway;

/**
 * A verified notification. The event key identifies the notification
 * itself (not the transaction), so a provider's retry is recognized.
 */
final class ProviderNotification
{
    /**
     * @param  array<string, mixed>  $payload  what is kept for the record, without secrets
     */
    public function __construct(
        public readonly string $eventKey,
        public readonly string $type,
        public readonly ?ProviderTransaction $transaction,
        public readonly array $payload,
    ) {}
}
