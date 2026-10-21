<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\CustomerType;

final class CustomerData
{
    public function __construct(
        public readonly CustomerType $type,
        public readonly string $name,
        public readonly ?string $documentType,
        public readonly ?string $documentNumber,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $businessId,
        public readonly ?string $notes,
    ) {}
}
