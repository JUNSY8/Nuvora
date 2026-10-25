<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * How the money arrived. Cash is counted at the drawer and may return
 * change. Card (an external card terminal), transfer and other are manual
 * payments, recorded with the reference of the operation.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta (datáfono)',
            self::Transfer => 'Transferencia',
            self::Other => 'Otro',
        };
    }
}
