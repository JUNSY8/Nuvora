<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Domain;

use RuntimeException;

final class TariffRuleViolation extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'TARIFF_RULE',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
