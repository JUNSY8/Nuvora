<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Controllers;

use App\Modules\Payments\Application\HandleProviderNotification;
use App\Modules\Payments\Application\OnlineCheckouts;
use App\Modules\Payments\Domain\PayableType;
use App\Modules\Payments\Presentation\Http\PaymentPayload;
use App\Modules\Payments\Presentation\Http\Requests\PaymentLinkRequest;
use App\Modules\Shared\Presentation\Http\Concerns\ResolvesActor;
use App\Modules\Shared\Presentation\Http\Responses\ApiResponse;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OnlinePaymentController
{
    use ResolvesActor;

    public function checkout(PaymentLinkRequest $request, OnlineCheckouts $checkouts, TenantContext $context): JsonResponse
    {
        $validated = $request->validated();

        $tenantId = (string) $context->id();
        $outcome = $checkouts->create(
            $this->actorId($request),
            $tenantId,
            PayableType::from((string) $validated['payable_type']),
            strtolower((string) $validated['payable_id']),
            strtolower((string) $validated['account_id']),
            isset($validated['amount']) ? (int) $validated['amount'] : null,
            $request->idempotencyKey(),
        );

        return ApiResponse::success([
            'payment' => PaymentPayload::one($tenantId, $outcome->payment),
            'replayed' => $outcome->replayed,
        ], $outcome->replayed ? 200 : 201, $request);
    }

    public function cancel(string $payment, Request $request, OnlineCheckouts $checkouts, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();

        return ApiResponse::success(['payment' => PaymentPayload::one($tenantId, $checkouts->cancel($this->actorId($request), $tenantId, $payment))], 200, $request);
    }

    public function sync(string $payment, Request $request, OnlineCheckouts $checkouts, TenantContext $context): JsonResponse
    {
        $tenantId = (string) $context->id();

        return ApiResponse::success(['payment' => PaymentPayload::one($tenantId, $checkouts->refresh($this->actorId($request), $tenantId, $payment))], 200, $request);
    }

    /**
     * Public page of a payment link: no session, the token is the capability.
     */
    public function publicCheckout(string $token, Request $request, OnlineCheckouts $checkouts): JsonResponse
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            abort(404);
        }

        return ApiResponse::success(['checkout' => $checkouts->publicView($token, $request->boolean('volver'))], 200, $request);
    }

    public function webhook(string $provider, string $account, Request $request, HandleProviderNotification $notifications): JsonResponse
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        $result = $notifications->handle($provider, strtolower($account), $headers, (string) $request->getContent());

        return response()->json(['received' => $result['status'] === 200, 'outcome' => $result['outcome']], $result['status']);
    }
}
