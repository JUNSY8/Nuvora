<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

/**
 * Answer of the membership access policy for one arrival (the README's
 * AccessPolicy → AccessDecision). A covered arrival parks under the
 * membership; a denied one parks as a visitor and the operator is told why,
 * since a lapsed member can still pay the regular tariff.
 */
final class AccessDecision
{
    private function __construct(
        public readonly bool $covered,
        public readonly string $code,
        public readonly string $reason,
    ) {}

    public static function covered(): self
    {
        return new self(true, 'COVERED', 'La mensualidad cubre la estancia.');
    }

    public static function denied(string $code, string $reason): self
    {
        return new self(false, $code, $reason);
    }
}
