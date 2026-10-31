<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\RecordAuditEvent;
use App\Modules\Payments\Domain\Gateway\ProviderUnavailable;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\ProviderStatus;
use App\Modules\Payments\Infrastructure\Persistence\Payment;
use App\Modules\Payments\Infrastructure\Providers\OnlineProviders;
use App\Modules\Tenancy\Application\TenantAuthorizer;
use App\Modules\Tenancy\Application\TenantDirectory;
use App\Modules\Tenancy\Domain\Permission;
use Carbon\CarbonImmutable;

/**
 * Compares the online payments of an account in a range of local days with
 * what the provider says. Differences Nuvora can resolve safely (a pending
 * payment the provider already approved or declined, a late approval, a
 * void) are applied; the rest are reported for a person to look at:
 *
 * - not_found: received in Nuvora, unknown to the provider;
 * - status_mismatch: received in Nuvora, not approved at the provider;
 * - amount_mismatch: the provider approved another amount;
 * - overpaid: the payable collected more than it charges;
 * - unreachable: the provider did not answer.
 */
final class ProviderReconciliation
{
    private const LIMIT = 200;

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantDirectory $tenants,
        private readonly ProviderAccounts $accounts,
        private readonly OnlineProviders $providers,
        private readonly ApplyProviderResult $apply,
        private readonly PayableSources $sources,
        private readonly PaymentLedger $ledger,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @return array{checked: int, fixed: int, issues: int, truncated: bool, items: list<array<string, mixed>>}
     */
    public function check(string $actorUserId, string $tenantId, string $accountId, string $fromDate, string $toDate): array
    {
        $this->authorizer->assert($actorUserId, $tenantId, Permission::PAYMENTS_RECONCILE);

        $account = $this->accounts->find($tenantId, $accountId);
        $adapter = $this->providers->for($account->provider);
        $timezone = $this->tenants->timezone($tenantId);

        $payments = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_account_id', $account->id)
            ->where('created_at', '>=', CarbonImmutable::parse($fromDate, $timezone)->startOfDay()->utc())
            ->where('created_at', '<', CarbonImmutable::parse($toDate, $timezone)->addDay()->startOfDay()->utc())
            ->orderBy('created_at')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $payments->count() > self::LIMIT;
        $items = [];
        $fixed = 0;

        foreach ($payments->take(self::LIMIT) as $payment) {
            $before = $payment->status;

            try {
                $transaction = $adapter->transaction($account->toGatewayAccount(), (string) $payment->id, $payment->provider_reference);
            } catch (ProviderUnavailable) {
                $items[] = self::row($payment, $before, null, 'unreachable', false);

                continue;
            }

            $outcome = $transaction === null || ! $transaction->status->isFinal() ? null : $this->apply->apply($tenantId, (string) $payment->id, $transaction, 'reconciliation');
            $payment->refresh();
            $changed = in_array($outcome, [ApplyProviderResult::COMPLETED, ApplyProviderResult::FAILED, ApplyProviderResult::VOIDED], true);
            $fixed += $changed ? 1 : 0;

            $issue = match (true) {
                $outcome === ApplyProviderResult::AMOUNT_MISMATCH => 'amount_mismatch',
                $transaction === null && $payment->status->isCollected() => 'not_found',
                $transaction !== null && $payment->status->isCollected() && ! in_array($transaction->status, [ProviderStatus::Approved, ProviderStatus::Voided], true) => 'status_mismatch',
                $payment->status->isCollected() && $this->overpaid($payment) => 'overpaid',
                default => null,
            };

            $items[] = self::row($payment, $before, $transaction?->status, $issue, $changed);
        }

        $issues = count(array_filter($items, fn (array $item): bool => $item['issue'] !== null));

        $this->audit->record('payment.provider_reconciled', 'payment_provider_account', (string) $account->id, $tenantId, $actorUserId, [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'checked' => count($items),
            'fixed' => $fixed,
            'issues' => $issues,
        ]);

        return ['checked' => count($items), 'fixed' => $fixed, 'issues' => $issues, 'truncated' => $truncated, 'items' => $items];
    }

    private function overpaid(Payment $payment): bool
    {
        $due = $this->sources->for($payment->payable_type)->lock((string) $payment->tenant_id, (string) $payment->payable_id)->amountDue;

        return (new PaymentBalance($due, $this->ledger->paid((string) $payment->tenant_id, $payment->payable_type, (string) $payment->payable_id)))->status() === 'overpaid';
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Payment $payment, PaymentStatus $before, ?ProviderStatus $provider, ?string $issue, bool $fixed): array
    {
        return [
            'payment_id' => $payment->id,
            'number' => $payment->number,
            'description' => $payment->description,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status_before' => $before->value,
            'status' => $payment->status->value,
            'provider_status' => $provider?->value,
            'issue' => $issue,
            'fixed' => $fixed,
        ];
    }
}
