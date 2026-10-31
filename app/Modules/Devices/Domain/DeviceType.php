<?php

declare(strict_types=1);

namespace App\Modules\Devices\Domain;

enum DeviceType: string
{
    case Camera = 'camera';
    case Barrier = 'barrier';
    case TicketPrinter = 'ticket_printer';
    case Display = 'display';
    case Sensor = 'sensor';
    case AccessReader = 'access_reader';
    case PaymentTerminal = 'payment_terminal';
    case Kiosk = 'kiosk';
}
