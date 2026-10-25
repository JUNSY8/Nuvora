<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\Gateway\InvalidProviderSignature;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Persistence\PaymentProviderAccount;
use App\Modules\Payments\Infrastructure\Persistence\PaymentProviderEvent;
use App\Modules\Payments\Infrastructure\Providers\OnlineProviders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhooks from providers. The URL names the account, but only the
 * signature made with that account's secret makes the notification
 * trusted; the tenant comes from the account, never from the body. The
 * payment is looked up inside that account, so a notification cannot touch
 * another tenant's payment.
 *
 * Answers: 404 for an unknown account, 401 for a bad signature, 200 once
 * recorded (also for duplicates and unknown references, so the provider
 * stops retrying), 500 when applying failed, so the provider retries.
 */
final class HandleProviderNotification
{
    public function __construct(
        private readonly OnlineProviders $providers,
        private readonly ApplyProviderResult $apply,
    ) {}

    /**
     * @param  array<string, string>  $headers  lower-case names
     * @return array{status: int, outcome: string}
     */
    public function handle(string $provider, string $accountId, array $headers, string $body): array
    {
        if (! $this->providers->has($provider)) {
            return ['status' => 404, 'outcome' => 'unknown_provider'];
        }

        $account = PaymentProviderAccount::query()->whereKey($accountId)->where('provider', $provider)->first();

        if ($account === null) {
            return ['status' => 404, 'outcome' => 'unknown_account'];
        }

        try {
            $notification = $this->providers->for($provider)->parseNotification($account->toGatewayAccount(), $headers, $body);
        } catch (InvalidProviderSignature) {
            Log::warning('payment_provider.invalid_signature', ['provider' => $provider, 'account_id' => $accountId]);

            return ['status' => 401, 'outcome' => 'invalid_signature'];
        }

        $tenantId = (string) $account->tenant_id;
        $reference = $notification->transaction?->reference;

        try {
            // Its own transaction (a savepoint inside an outer one), so a duplicate key does not abort the caller's.
            $event = DB::transaction(fn (): PaymentProviderEvent => PaymentProviderEvent::query()->create([
                'tenant_id' => $tenantId,
                'provider_account_id' => $account->id,
                'provider' => $provider,
                'event_key' => mb_substr($notification->eventKey, 0, 128),
                'event_type' => mb_substr($notification->type, 0, 60),
                'reference' => $reference === null ? null : mb_substr($reference, 0, 100),
                'status' => 'received',
                'payload' => $notification->payload,
                'received_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            $event = PaymentProviderEvent::query()->where('provider_account_id', $account->id)->where('event_key', mb_substr($notification->eventKey, 0, 128))->firstOrFail();

            // A retry of a notification already applied: nothing to do.
            if (in_array($event->status, ['processed', 'ignored'], true)) {
                return ['status' => 200, 'outcome' => 'duplicate'];
            }
        }

        $payment = $reference === null ? null : Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_account_id', $account->id)
            ->whereKey($reference)
            ->first();

        if ($notification->transaction === null || $payment === null) {
            $event->fill(['status' => 'ignored', 'outcome' => $notification->transaction === null ? 'Sin transacción.' : 'Referencia desconocida.', 'processed_at' => now()])->save();

            return ['status' => 200, 'outcome' => 'ignored'];
        }

        try {
            $outcome = $this->apply->apply($tenantId, (string) $payment->id, $notification->transaction, 'webhook');
        } catch (Throwable $exception) {
            $event->fill(['status' => 'failed', 'payment_id' => $payment->id, 'outcome' => mb_substr($exception->getMessage(), 0, 255)])->save();
            report($exception);

            return ['status' => 500, 'outcome' => 'failed'];
        }

        $event->fill(['status' => 'processed', 'payment_id' => $payment->id, 'outcome' => $outcome, 'processed_at' => now()])->save();

        return ['status' => 200, 'outcome' => $outcome];
    }
}
