<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id');

        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
