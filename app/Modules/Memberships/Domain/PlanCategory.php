<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain;

/**
 * Kind of membership a plan sells. It labels and filters plans; the rules
 * (vehicles, lots, zones, hours, price) live in the plan itself, so a VIP
 * or corporate plan is a configuration, not special code.
 */
enum PlanCategory: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Corporate = 'corporate';
    case Vip = 'vip';
    case Employee = 'employee';
    case Resident = 'resident';
    case Student = 'student';
    case Other = 'other';
}
