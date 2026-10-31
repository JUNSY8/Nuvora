<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Domain\Gateway\ProviderUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calls to providers: short timeouts and a couple of quick retries on
 * network errors and 5xx answers, since webhooks and the sync job retry
 * the rest later. Bodies are never logged: they may carry tokens.
 */
final class ProviderHttp
{
    public static function client(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(2, 300, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    public static function send(string $provider, string $operation, callable $call): Response
    {
        try {
            $response = $call(self::client());
        } catch (ConnectionException $exception) {
            Log::warning('payment_provider.unreachable', ['provider' => $provider, 'operation' => $operation]);

            throw new ProviderUnavailable("{$provider} no respondió.", previous: $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('payment_provider.error', ['provider' => $provider, 'operation' => $operation, 'status' => $response->status()]);

            throw new ProviderUnavailable("{$provider} respondió con un error ({$response->status()}).");
        }

        return $response;
    }
}
