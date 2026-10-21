<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation\Http\Controllers;

use App\Modules\Customers\Application\ChangeCustomerStatus;
use App\Modules\Customers\Application\CreateCustomer;
use App\Modules\Customers\Application\ListCustomers;
use App\Modules\Customers\Application\ShowCustomer;
use App\Modules\Customers\Application\UpdateCustomer;
use App\Modules\Customers\Domain\CustomerStatus;
use App\Modules\Customers\Domain\CustomerType;
use App\Modules\Customers\Infrastructure\Persistence\Customer;
use App\Modules\Customers\Presentation\Http\CustomerPayload;
use App\Modules\Customers\Presentation\Http\Requests\CustomerRequest;
use App\Modules\Customers\Presentation\Http\Requests\CustomerStatusRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CustomerController
{
    use ResolvesActor;

    public function index(Request $request, ListCustomers $listCustomers, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(CustomerType::class)],
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'business_id' => ['nullable', 'ulid'],
            'search' => ['nullable', 'string', 'max:80'],
            'organizations' => ['nullable', 'boolean'],
        ]);

        [$page, $perPage] = $this->pagination($request, 25, 100);

        $result = $listCustomers->handle(
            actorUserId: $this->actorId($request),
            tenantId: (string) $context->id(),
            page: $page,
            perPage: $perPage,
            type: isset($filters['type']) ? CustomerType::from((string) $filters['type']) : null,
            status: isset($filters['status']) ? CustomerStatus::from((string) $filters['status']) : null,
            businessId: isset($filters['business_id']) ? strtolower((string) $filters['business_id']) : null,
            search: $filters['search'] ?? null,
            organizationsOnly: $request->boolean('organizations'),
        );

        return ApiResponse::success([
            'items' => collect($result['customers']->items())->map(fn (Customer $customer): array => CustomerPayload::from($customer))->values()->all(),
            'meta' => PageMeta::from($result['customers']),
            'type_counts' => $result['type_counts'],
        ], 200, $request);
    }

    public function show(Request $request, string $customer, ShowCustomer $showCustomer, TenantContext $context): JsonResponse
    {
        $found = $showCustomer->handle($this->actorId($request), (string) $context->id(), $customer);

        return ApiResponse::success(['customer' => CustomerPayload::from($found)], 200, $request);
    }

    public function store(CustomerRequest $request, CreateCustomer $createCustomer, TenantContext $context): JsonResponse
    {
        $customer = $createCustomer->handle($this->actorId($request), (string) $context->id(), $request->toData());

        return ApiResponse::success(['customer' => CustomerPayload::from($customer)], 201, $request);
    }

    public function update(string $customer, CustomerRequest $request, UpdateCustomer $updateCustomer, TenantContext $context): JsonResponse
    {
        $updated = $updateCustomer->handle($this->actorId($request), (string) $context->id(), $customer, $request->toData());

        return ApiResponse::success(['customer' => CustomerPayload::from($updated)], 200, $request);
    }

    public function status(string $customer, CustomerStatusRequest $request, ChangeCustomerStatus $changeCustomerStatus, TenantContext $context): JsonResponse
    {
        $updated = $changeCustomerStatus->handle(
            $this->actorId($request),
            (string) $context->id(),
            $customer,
            CustomerStatus::from((string) $request->validated('status')),
        );

        return ApiResponse::success(['customer' => CustomerPayload::from($updated)], 200, $request);
    }
}
