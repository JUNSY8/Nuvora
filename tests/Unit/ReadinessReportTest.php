<?php

namespace Tests\Unit;

use App\Modules\Shared\Application\Readiness\ReadinessReport;
use PHPUnit\Framework\TestCase;

class ReadinessReportTest extends TestCase
{
    public function test_report_is_ready_only_when_every_check_passes(): void
    {
        $ready = new ReadinessReport([
            'database' => 'ok',
            'redis' => 'ok',
        ]);

        $this->assertTrue($ready->isReady());
        $this->assertSame('ok', $ready->toArray()['status']);

        $degraded = new ReadinessReport([
            'database' => 'ok',
            'redis' => 'failed',
        ]);

        $this->assertFalse($degraded->isReady());
        $this->assertSame('degraded', $degraded->toArray()['status']);
    }
}
