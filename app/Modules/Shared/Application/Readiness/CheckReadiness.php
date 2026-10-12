<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Readiness;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CheckReadiness
{
    public function handle(): ReadinessReport
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
            'redis' => $this->check(function (): void {
                $pong = Redis::connection()->ping();
                $normalized = is_object($pong) ? (string) $pong : $pong;

                if (! in_array($normalized, [true, 'PONG', '+PONG', '1'], true)) {
                    throw new \RuntimeException('Redis did not respond to ping.');
                }
            }),
            'queue' => $this->check(function (): void {
                $driver = (string) config('queue.default');

                if ($driver === '') {
                    throw new \RuntimeException('Queue driver is not configured.');
                }
            }),
            'storage' => $this->check(function (): void {
                $disk = Storage::disk((string) config('filesystems.default'));
                $probe = '.nuvora-readiness-'.bin2hex(random_bytes(4));
                $disk->put($probe, 'ok');
                $disk->delete($probe);
            }),
        ];

        return new ReadinessReport($checks);
    }

    /**
     * @param  callable(): mixed  $probe
     */
    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'failed';
        }
    }
}
