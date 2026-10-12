<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http\Controllers;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Application\AddTenantMember;
use App\Modules\Tenancy\Application\ChangeMembershipStatus;
use App\Modules\Tenancy\Application\ListTenantMembers;
use App\Modules\Tenancy\Application\SyncMembershipRoles;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use App\Modules\Tenancy\Presentation\Http\MemberPayload;
use App\Modules\Tenancy\Presentation\Http\Requests\AddTenantMemberRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\SyncMembershipRolesRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\UpdateMembershipStatusRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantMemberController
{
    public function index(ListTenantMembers $listTenantMembers, TenantContext $context, Request $request): JsonResponse
    {
        $members = $listTenantMembers->handle($this->actorId($request), (string) $context->id());

        return ApiResponse::success([
            'members' => $members->map(fn ($membership): array => MemberPayload::from($membership))->values()->all(),
        ], 200, $request);
    }

    public function store(
        AddTenantMemberRequest $request,
        AddTenantMember $addTenantMember,
        TenantContext $context,
    ): JsonResponse {
        /** @var list<string> $roles */
        $roles = array_values($request->validated('roles'));

        $membership = $addTenantMember->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            email: (string) $request->validated('email'),
            roleKeys: $roles,
        );

        return ApiResponse::success([
            'member' => MemberPayload::from($membership),
        ], 201, $request);
    }

    public function update(
        UpdateMembershipStatusRequest $request,
        ChangeMembershipStatus $changeMembershipStatus,
        TenantContext $context,
        string $membership,
    ): JsonResponse {
        $updated = $changeMembershipStatus->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            membershipId: $membership,
            status: MembershipStatus::from((string) $request->validated('status')),
        );

        return ApiResponse::success([
            'member' => MemberPayload::from($updated),
        ], 200, $request);
    }

    public function syncRoles(
        SyncMembershipRolesRequest $request,
        SyncMembershipRoles $syncMembershipRoles,
        TenantContext $context,
        string $membership,
    ): JsonResponse {
        /** @var list<string> $roles */
        $roles = array_values($request->validated('roles'));

        $updated = $syncMembershipRoles->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            membershipId: $membership,
            roleKeys: $roles,
        );

        return ApiResponse::success([
            'member' => MemberPayload::from($updated),
        ], 200, $request);
    }

    private function actorId(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return (string) $user->id;
    }
}
