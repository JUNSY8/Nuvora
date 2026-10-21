<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain;

enum CustomerType: string
{
    case Individual = 'individual';
    case Business = 'business';
    case Corporate = 'corporate';

    /**
     * Business and corporate customers are organizations: they can group
     * affiliated individuals (employees, partners) and their vehicles.
     */
    public function isOrganization(): bool
    {
        return $this !== self::Individual;
    }
}
