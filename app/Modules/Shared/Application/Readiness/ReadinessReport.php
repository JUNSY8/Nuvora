<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Readiness;

final class ReadinessReport
{
    /**
     * @param  array<string, string>  $checks
     */
    public function __construct(
        public readonly array $checks,
    ) {}

    public function isReady(): bool
    {
        return ! in_array('failed', $this->checks, true);
    }

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isReady() ? 'ok' : 'degraded',
            'checks' => $this->checks,
        ];
    }
}
