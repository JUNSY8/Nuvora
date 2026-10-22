<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Realtime;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Change marker per channel (a tenant or a lot). Writers touch it after their
 * transaction commits; screens poll the version and reload from the API when
 * it changes. The database stays authoritative: losing the cache only costs
 * one extra reload, and a failure here never breaks the business operation.
 * A WebSocket transport (Reverb) can replace the polling without touching
 * the writers. See ADR-015.
 */
final class LiveUpdates
{
    private const PREFIX = 'live:';

    public function __construct(private readonly Repository $cache) {}

    public static function tenant(string $tenantId): string
    {
        return 'tenant.'.$tenantId;
    }

    public static function lot(string $tenantId, string $lotId): string
    {
        return 'tenant.'.$tenantId.'.lot.'.$lotId;
    }

    public function touch(string ...$channels): void
    {
        DB::afterCommit(function () use ($channels): void {
            $version = strtolower((string) Str::ulid());

            foreach ($channels as $channel) {
                try {
                    $this->cache->forever(self::PREFIX.$channel, $version);
                } catch (Throwable $exception) {
                    Log::warning('live_updates.touch_failed', ['channel' => $channel, 'exception' => $exception::class]);
                }
            }
        });
    }

    public function version(string $channel): string
    {
        try {
            $version = $this->cache->get(self::PREFIX.$channel);
        } catch (Throwable $exception) {
            Log::warning('live_updates.read_failed', ['channel' => $channel, 'exception' => $exception::class]);

            return 'unavailable';
        }

        return is_string($version) ? $version : 'none';
    }
}
