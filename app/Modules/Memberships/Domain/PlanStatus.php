<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

/**
 * An inactive plan is no longer sold; memberships already sold keep it.
 */
enum PlanStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
