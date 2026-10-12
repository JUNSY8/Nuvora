<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http\Middleware;

use App\Modules\Tenancy\Domain\PermissionDenied;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantPermission
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->context->allows($permission)) {
            throw new PermissionDenied;
        }

        return $next($request);
    }
}
