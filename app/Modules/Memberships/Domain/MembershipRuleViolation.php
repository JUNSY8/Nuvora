<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

use RuntimeException;

final class MembershipRuleViolation extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MEMBERSHIP_RULE',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
