<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
