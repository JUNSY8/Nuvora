<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http\Middleware;

use App\Modules\Identity\Domain\EmailNotVerified;
use App\Modules\Tenancy\Domain\TenantContextMissing;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'hasVerifiedEmail') || ! $user->hasVerifiedEmail()) {
            throw new EmailNotVerified;
        }

        if ($this->context->id() === null) {
            throw new TenantContextMissing;
        }

        return $next($request);
    }
}
