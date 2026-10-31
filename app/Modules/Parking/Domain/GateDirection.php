<?php

declare(strict_types=1);

namespace App\Modules\Parking\Domain;

enum GateDirection: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Bidirectional = 'bidirectional';
}
