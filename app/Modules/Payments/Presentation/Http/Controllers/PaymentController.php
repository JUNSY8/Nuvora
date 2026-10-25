<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Controllers;

use App\Modules\Payments\Application\CollectPayment;
use App\Modules\Payments\Application\ListPayments;
use App\Modules\Payments\Application\PaymentFilters;
use App\Modules\Payments\Application\PaymentOutcome;
use App\Modules\Payments\Application\PaymentReceipt;
use App\Modules\Payments\Application\RefundPayment;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Presentation\Http\PaymentPayload;
use App\Modules\Payments\Presentation\Http\Requests\PaymentRequest;
use App\Modules\Payments\Presentation\Http\Requests\RefundRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Shared\Presentation\Http\Responses\PageMeta;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentController
{
    use ResolvesActor;

    public function index(Request $request, ListPayments $listPayments, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'lot_id' => ['nullable', 'ulid'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'payable_type' => ['nullable', Rule::enum(PayableType::class)],
            'payable_id' => ['nullable', 'ulid', 'required_with:payable_type'],
            'received_by' => ['nullable', 'ulid'],
            'reconciled' => ['nullable', 'boolean'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:20'],
        ]);

        [$page, $perPage] = $this->pagination($request, 25, 100);
        $tenantId = (string) $context->id();
        $paymentFilters = new PaymentFilters(
            lotId: self::ulid($filters['lot_id'] ?? null),
            method: isset($filters['method']) ? PaymentMethod::from((string) $filters['method']) : null,
            status: isset($filters['status']) ? PaymentStatus::from((string) $filters['status']) : null,
            payableType: isset($filters['payable_type']) ? PayableType::from((string) $filters['payable_type']) : null,
            payableId: self::ulid($filters['payable_id'] ?? null),
            receivedBy: self::ulid($filters['received_by'] ?? null),
            fromDate: $filters['from_date'] ?? null,
            toDate: $filters['to_date'] ?? null,
            search: $filters['search'] ?? null,
            reconciled: isset($filters['reconciled']) ? (bool) $filters['reconciled'] : null,
        );

        $payments = $listPayments->handle($this->actorId($request), $tenantId, $paymentFilters, $page, $perPage);

        return ApiResponse::success([
            'items' => PaymentPayload::many($tenantId, $payments->items()),
            'meta' => PageMeta::from($payments),
            'totals' => $listPayments->totals($tenantId, $paymentFilters),
        ], 200, $request);
    }

    public function show(Request $request, string $payment, ListPayments $listPayments, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();
        $found = $listPayments->show($this->actorId($request), $tenantId, $payment);

        return ApiResponse::success(['payment' => PaymentPayload::one($tenantId, $found)], 200, $request);
    }

    public function receipt(Request $request, string $payment, PaymentReceipt $receipt, TenantContext $context): JsonResponse
    {
        return ApiResponse::success(['receipt' => $receipt->handle($this->actorId($request), (string) $context->id(), $payment)], 200, $request);
    }

    public function store(PaymentRequest $request, CollectPayment $collect, TenantContext $context): JsonResponse
    {
        $outcome = $collect->handle($this->actorId($request), (string) $context->id(), $request->toData(), $request->idempotencyKey());

        return $this->respond($request, $outcome, 201, $context);
    }

    public function refund(string $payment, RefundRequest $request, RefundPayment $refund, TenantContext $context): JsonResponse
    {
        $outcome = $refund->handle(
            $this->actorId($request),
            (string) $context->id(),
            $payment,
            $request->amount(),
            (string) $request->validated('reason'),
            $request->idempotencyKey(),
        );

        return $this->respond($request, $outcome, 201, $context);
    }

    private function respond(Request $request, PaymentOutcome $outcome, int $status, TenantContext $context): JsonResponse
    {
        return ApiResponse::success([
            'payment' => PaymentPayload::one((string) $context->id(), $outcome->payment),
            'replayed' => $outcome->replayed,
        ], $outcome->replayed ? 200 : $status, $request);
    }

    private static function ulid(mixed $value): ?string
    {
        return is_string($value) ? strtolower($value) : null;
    }
}
