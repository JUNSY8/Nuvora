<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\TenantNotAccessible;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Tenancy\Infrastructure\TenantDatabaseSession;
use Illuminate\Http\Request;

final class EstablishTenantContext
{
    public function __construct(
        private readonly MembershipAccessReader $memberships,
        private readonly TenantContext $context,
        private readonly TenantDatabaseSession $database,
    ) {}

    public function handle(Request $request, bool $strictHeader, ?string $preferredTenantId = null): void
    {
        $this->context->clear();

        $user = $request->user();

        if ($user === null || ! method_exists($user, 'hasVerifiedEmail') || ! $user->hasVerifiedEmail()) {
            $this->database->apply(null);

            return;
        }

        $userId = (string) $user->getAuthIdentifier();

        if (is_string($preferredTenantId) && $preferredTenantId !== '') {
            $preferred = $this->memberships->find($userId, $preferredTenantId);

            if ($preferred === null) {
                $this->database->apply(null);

                throw new TenantNotAccessible;
            }

            $this->activate($request, $preferred);

            return;
        }

        $header = $request->header('X-Tenant-Id');
        $header = is_string($header) ? trim($header) : '';

        if ($header !== '') {
            $resolved = $this->memberships->find($userId, $header);

            if ($resolved === null) {
                if ($strictHeader) {
                    $this->database->apply(null);

                    throw new TenantNotAccessible;
                }
            } else {
                $this->activate($request, $resolved);

                return;
            }
        }

        $sessionTenantId = $request->hasSession() ? $request->session()->get('tenant_id') : null;

        if (is_string($sessionTenantId) && $sessionTenantId !== '') {
            $resolved = $this->memberships->find($userId, $sessionTenantId);

            if ($resolved !== null) {
                $this->activate($request, $resolved);

                return;
            }

            $request->session()->forget('tenant_id');
        }

        $sole = $this->memberships->sole($userId);

        if ($sole !== null) {
            $this->activate($request, $sole);

            return;
        }

        $this->database->apply(null);
    }

    private function activate(Request $request, ResolvedTenant $tenant): void
    {
        $this->context->set($tenant->id, $tenant->permissions);

        if ($request->hasSession()) {
            $request->session()->put('tenant_id', $tenant->id);
        }

        $this->database->apply($tenant->id);
    }
}
