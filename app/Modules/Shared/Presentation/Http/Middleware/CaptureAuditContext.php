<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Http\Middleware;

use App\Modules\Audit\Infrastructure\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CaptureAuditContext
{
    public function __construct(private readonly AuditContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        $this->context->ipAddress = $request->ip();

        $agent = $request->userAgent();
        $this->context->userAgent = is_string($agent) ? mb_substr($agent, 0, 255) : null;

        $requestId = $request->attributes->get('request_id');
        $this->context->requestId = is_string($requestId) ? $requestId : null;

        return $next($request);
    }
}
