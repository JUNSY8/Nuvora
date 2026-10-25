<?php

declare(strict_types=1);

namespace App\Modules\ParkingSessions\Application;

use App\Modules\ParkingSessions\Domain\SessionStatus;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Domain\Permission;

/**
 * Price of an open stay if it left now, shown before confirming the exit.
 * Nothing is stored. The quote carries a token that holds this price for a
 * few minutes; without it, the exit prices again at its own moment.
 */
final class QuoteSession
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly SessionLookup $lookup,
        private readonly SessionPricing $pricing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $actorUserId, string $tenantId, string $sessionId, ?string $discountId): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_VIEW);

        if ($discountId !== null) {
            $this->authorizer->assert($actorUserId, $tenantId, Permission::SESSIONS_DISCOUNT);
        }

        $session = $this->lookup->find($tenantId, $sessionId);

        if ($session->status !== SessionStatus::Open) {
            throw RegisterExit::notOpen($session);
        }

        $pricedAt = now()->toImmutable();
        $hold = QuoteToken::issue($tenantId, (string) $session->id, $discountId, $pricedAt);

        return [
            ...$this->pricing->preview($tenantId, $session, $pricedAt, $discountId),
            'priced_at' => $pricedAt->toIso8601String(),
            'quote_token' => $hold['token'],
            'quote_expires_at' => $hold['expires_at'],
        ];
    }
}
