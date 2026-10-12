<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
