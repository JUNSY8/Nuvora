<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

final class JsonLogFormatter
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new JsonFormatter);
        }
    }
}
