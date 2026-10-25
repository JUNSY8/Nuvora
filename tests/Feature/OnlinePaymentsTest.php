<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Tenancy\Infrastructure\Persistence\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithMemberships;
use Tests\Concerns\InteractsWithOnlinePayments;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithParkingSessions;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTariffs;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OnlinePaymentsTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithMemberships;
    use InteractsWithOnlinePayments;
    use InteractsWithParking;
    use InteractsWithParkingSessions;
    use InteractsWithPayments;
    use InteractsWithTariffs;
    use InteractsWithTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * A closed stay of 1 h 30 min that owes $6.000.
     *
     * @return array{0: User, 1: Tenant, 2: string, 3: string}
     */
    private function owingStay(string $plate = 'WEB123'): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createTariff($owner, $lot);
        $session = $this->enter($owner, $lot, $plate)->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();
        $this->exitWith($owner, $session)->assertOk()->assertJsonPath('data.session.payment.balance', self::cop(6000));

        return [$owner, $tenant, $lot, $session];
    }

    public function test_provider_accounts_keep_their_credentials_secret(): void
    {
        [$owner, $tenant] = $this->createTenantOwner();
        [$other] = $this->createTenantOwner('Otra empresa');
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');

        $this->actingAs($owner)->postJson('/api/v1/payment-providers', [
            'provider' => 'wompi', 'name' => 'Wompi', 'environment' => 'sandbox', 'status' => 'active',
            'credentials' => [...$this->wompiCredentials, 'private_key' => ''],
        ])->assertUnprocessable()->assertJsonValidationErrors(['credentials.private_key'], 'error.details');

        $response = $this->actingAs($owner)->postJson('/api/v1/payment-providers', [
            'provider' => 'wompi', 'name' => 'Wompi principal', 'environment' => 'sandbox', 'status' => 'active', 'credentials' => $this->wompiCredentials,
        ])->assertCreated()
            ->assertJsonPath('data.account.credentials.private_key', '••••5678')
            ->assertJsonPath('data.account.credentials.events_secret', '••••9012');
        $account = $response->json('data.account.id');
        $this->assertStringEndsWith("/api/v1/webhooks/payments/wompi/{$account}", $response->json('data.account.notification_url'));
        $this->assertStringNotContainsString('prv_test_nuvora5678', $response->getContent());

        // Encrypted at rest and never audited.
        $this->assertStringNotContainsString('prv_test_nuvora5678', (string) DB::table('payment_provider_accounts')->where('id', $account)->value('credentials'));
        $this->assertStringNotContainsString('prv_test_nuvora5678', (string) DB::table('audit_logs')->where('resource_id', $account)->value('properties'));

        // An empty credential keeps the stored one; a new one replaces it.
        $this->actingAs($owner)->patchJson("/api/v1/payment-providers/{$account}", [
            'provider' => 'wompi', 'name' => 'Wompi', 'environment' => 'production', 'status' => 'active',
            'credentials' => ['public_key' => '', 'private_key' => 'prv_prod_00009999', 'events_secret' => null, 'integrity_secret' => ''],
        ])->assertOk()
            ->assertJsonPath('data.account.environment', 'production')
            ->assertJsonPath('data.account.credentials.public_key', '••••1234')
            ->assertJsonPath('data.account.credentials.private_key', '••••9999');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_provider.updated', 'resource_id' => $account]);

        $this->actingAs($owner)->patchJson("/api/v1/payment-providers/{$account}", [
            'provider' => 'payu', 'name' => 'Wompi', 'environment' => 'sandbox', 'status' => 'active', 'credentials' => [],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'PROVIDER_IMMUTABLE');

        // A cashier sees the active accounts to send links, not their configuration.
        $this->actingAs($cashier)->getJson('/api/v1/payment-providers')->assertForbidden();
        $this->actingAs($cashier)->getJson('/api/v1/payment-providers/options')->assertOk()
            ->assertJsonPath('data.items.0.id', $account)
            ->assertJsonMissingPath('data.items.0.credentials');

        $this->actingAs($other)->patchJson("/api/v1/payment-providers/{$account}", [
            'provider' => 'wompi', 'name' => 'Ajena', 'environment' => 'sandbox', 'status' => 'inactive', 'credentials' => [],
        ])->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/payment-providers')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_a_payment_link_is_paid_through_wompi(): void
    {
        [$owner, , , $session] = $this->owingStay();
        $account = $this->createProviderAccount($owner);

        $link = $this->paymentLink($owner, 'parking_session', $session, $account, null, ['Idempotency-Key' => 'link-web123-0001'])
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.number', null)
            ->assertJsonPath('data.payment.provider', 'wompi')
            ->assertJsonPath('data.payment.amount', self::cop(6000))
            ->json('data.payment');
        $this->paymentLink($owner, 'parking_session', $session, $account, null, ['Idempotency-Key' => 'link-web123-0001'])
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.payment.id', $link['id']);
        $this->paymentLink($owner, 'parking_session', $session, $account)
            ->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_PENDING');

        // Pending money does not count yet.
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}")->assertOk()->assertJsonPath('data.session.payment.status', 'pending');

        $token = $this->checkoutToken((string) $link['checkout_url']);
        $public = $this->getJson("/api/v1/public/checkouts/{$token}")
            ->assertOk()
            ->assertJsonPath('data.checkout.status', 'pending')
            ->assertJsonPath('data.checkout.tenant.name', 'Parqueadero Central')
            ->assertJsonPath('data.checkout.provider', 'Wompi')
            ->assertJsonPath('data.checkout.checkout.method', 'GET')
            ->assertJsonPath('data.checkout.checkout.fields.reference', $link['id'])
            ->assertJsonPath('data.checkout.checkout.fields.amount-in-cents', (string) self::cop(6000))
            ->json('data.checkout.checkout.fields');
        $this->assertSame(hash('sha256', $link['id'].self::cop(6000).'COP'.$public['expiration-time'].$this->wompiCredentials['integrity_secret']), $public['signature:integrity']);
        $this->assertStringNotContainsString($this->wompiCredentials['private_key'], json_encode($public) ?: '');
        $this->getJson('/api/v1/public/checkouts/'.str_repeat('x', 48))->assertNotFound();

        // A forged notification changes nothing.
        $forged = $this->wompiEvent($link['id'], 'APPROVED', self::cop(6000));
        $forged['signature']['checksum'] = hash('sha256', 'forged');
        $this->sendWompiEvent($account, $forged)->assertUnauthorized();
        $this->assertSame('pending', DB::table('payments')->where('id', $link['id'])->value('status'));

        $event = $this->wompiEvent($link['id'], 'APPROVED', self::cop(6000), type: 'NEQUI');
        $this->sendWompiEvent($account, $event)->assertOk()->assertJsonPath('outcome', 'completed');
        $this->sendWompiEvent($account, $event)->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->actingAs($owner)->getJson("/api/v1/payments/{$link['id']}")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'completed')
            ->assertJsonPath('data.payment.number', 'RC-000001')
            ->assertJsonPath('data.payment.method', 'transfer')
            ->assertJsonPath('data.payment.provider_method', 'NEQUI')
            ->assertJsonPath('data.payment.provider_reference', 'tx-wompi-1')
            ->assertJsonPath('data.payment.checkout_url', null);
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}")->assertOk()->assertJsonPath('data.session.payment.status', 'paid');
        $this->getJson("/api/v1/public/checkouts/{$token}")->assertOk()->assertJsonPath('data.checkout.status', 'completed')->assertJsonPath('data.checkout.checkout', null);

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('payment_provider_events')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.confirmed', 'resource_id' => $link['id']]);

        // Online money never passes through a drawer.
        $this->actingAs($owner)->getJson('/api/v1/payment-reconciliations/preview')->assertOk()->assertJsonCount(0, 'data.currencies');
    }

    public function test_notifications_ignore_bad_amounts_other_tenants_and_late_pending_answers(): void
    {
        [$owner, , , $session] = $this->owingStay();
        $account = $this->createProviderAccount($owner);
        $payment = $this->paymentLink($owner, 'parking_session', $session, $account)->assertCreated()->json('data.payment.id');

        // Another tenant's account cannot reach this payment, even with a valid signature for itself.
        [$intruder] = $this->createTenantOwner('Intrusa');
        $intruderAccount = $this->createProviderAccount($intruder);
        $this->sendWompiEvent($intruderAccount, $this->wompiEvent($payment, 'APPROVED', self::cop(6000)))
            ->assertOk()->assertJsonPath('outcome', 'ignored');

        $this->sendWompiEvent($account, $this->wompiEvent($payment, 'APPROVED', self::cop(10), 'tx-short', 1700000001))
            ->assertOk()->assertJsonPath('outcome', 'amount_mismatch');
        $this->assertSame('pending', DB::table('payments')->where('id', $payment)->value('status'));

        $this->sendWompiEvent($account, $this->wompiEvent('unknown-reference', 'APPROVED', self::cop(6000), 'tx-x', 1700000002))
            ->assertOk()->assertJsonPath('outcome', 'ignored');
        $this->postJson('/api/v1/webhooks/payments/wompi/01jaaaaaaaaaaaaaaaaaaaaaaa', $this->wompiEvent($payment, 'APPROVED', self::cop(6000)))->assertNotFound();

        $this->sendWompiEvent($account, $this->wompiEvent($payment, 'DECLINED', self::cop(6000), 'tx-1', 1700000003))
            ->assertOk()->assertJsonPath('outcome', 'failed');
        $this->assertSame('failed', DB::table('payments')->where('id', $payment)->value('status'));
        $this->assertNull(DB::table('payments')->where('id', $payment)->value('number'));

        // A retry of the same declined attempt approved later still counts.
        $this->sendWompiEvent($account, $this->wompiEvent($payment, 'APPROVED', self::cop(6000), 'tx-2', 1700000004))
            ->assertOk()->assertJsonPath('outcome', 'completed');
        $this->sendWompiEvent($account, $this->wompiEvent($payment, 'PENDING', self::cop(6000), 'tx-2', 1700000005))
            ->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertSame('completed', DB::table('payments')->where('id', $payment)->value('status'));
    }

    public function test_payu_confirms_by_form_and_refunds_are_followed_up(): void
    {
        [$owner, , , $session] = $this->owingStay();
        $account = $this->createProviderAccount($owner, 'payu');
        $payment = $this->paymentLink($owner, 'parking_session', $session, $account)->assertCreated()->json('data.payment');
        $token = $this->checkoutToken((string) $payment['checkout_url']);

        $this->getJson("/api/v1/public/checkouts/{$token}")
            ->assertOk()
            ->assertJsonPath('data.checkout.checkout.method', 'POST')
            ->assertJsonPath('data.checkout.checkout.fields.amount', '6000.00')
            ->assertJsonPath('data.checkout.checkout.fields.signature', md5($this->payuCredentials['api_key'].'~508029~'.$payment['id'].'~6000.00~COP'));

        $this->sendPayUConfirmation($account, $payment['id'], '6000.00', '4')->assertOk()->assertJsonPath('outcome', 'completed');
        $this->assertSame(['order_id' => '880011'], json_decode((string) DB::table('payments')->where('id', $payment['id'])->value('provider_data'), true));

        // PayU returns the whole payment only; a partial refund is made by hand.
        $this->refund($owner, $payment['id'], ['amount' => self::cop(1000)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'PARTIAL_REFUND_UNSUPPORTED');
        $this->refund($owner, $payment['id'], ['amount' => self::cop(1000), 'channel' => 'manual', 'manual_method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.payment.refunds.0.provider', 'offline')->assertJsonPath('data.payment.refunds.0.status', 'completed');

        Http::fake(['sandbox.api.payulatam.com/payments-api/*' => Http::response(['code' => 'SUCCESS', 'transactionResponse' => ['state' => 'PENDING', 'transactionId' => 'rf-1']])]);
        $this->refund($owner, $payment['id'])
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'refunded')
            ->assertJsonPath('data.payment.refunds.1.provider', 'payu')
            ->assertJsonPath('data.payment.refunds.1.status', 'pending');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'payments-api') && $request['transaction']['type'] === 'REFUND' && $request['transaction']['parentTransactionId'] === 'tx-payu-1' && $request['transaction']['order']['id'] === '880011');

        // The sync job follows the pending refund until PayU approves it.
        Http::fake(['sandbox.api.payulatam.com/reports-api/*' => Http::response(['code' => 'SUCCESS', 'result' => ['payload' => [[
            'id' => 880011,
            'status' => 'CAPTURED',
            'transactions' => [
                ['id' => 'tx-payu-1', 'type' => 'AUTHORIZATION_AND_CAPTURE', 'transactionResponse' => ['state' => 'APPROVED']],
                ['id' => 'rf-1', 'type' => 'REFUND', 'transactionResponse' => ['state' => 'APPROVED']],
            ],
            'additionalValues' => ['TX_VALUE' => ['value' => 6000.0, 'currency' => 'COP']],
        ]]]])]);
        $this->artisan('payments:sync')->assertSuccessful();
        $this->assertSame('completed', DB::table('payment_refunds')->where('provider', 'payu')->value('status'));
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}")->assertOk()->assertJsonPath('data.session.payment.status', 'pending');
    }

    public function test_a_refund_the_provider_rejects_gives_the_amount_back(): void
    {
        [$owner, , , $session] = $this->owingStay();
        $account = $this->createProviderAccount($owner);
        $payment = $this->paymentLink($owner, 'parking_session', $session, $account)->assertCreated()->json('data.payment.id');
        $this->sendWompiEvent($account, $this->wompiEvent($payment, 'APPROVED', self::cop(6000)))->assertOk();

        Http::fake(['sandbox.wompi.co/v1/transactions/tx-wompi-1/void' => Http::sequence()
            ->push(['error' => ['type' => 'INPUT_VALIDATION_ERROR', 'reason' => 'No se puede anular']], 422)
            ->push(['data' => ['status' => 'VOIDED']])]);
        $this->refund($owner, $payment)->assertStatus(502)->assertJsonPath('error.code', 'REFUND_FAILED');
        $this->assertDatabaseHas('payment_refunds', ['payment_id' => $payment, 'status' => 'failed']);
        $this->assertDatabaseHas('payments', ['id' => $payment, 'status' => 'completed', 'refunded_amount' => 0]);

        $this->refund($owner, $payment)->assertCreated()->assertJsonPath('data.payment.status', 'refunded');
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer '.$this->wompiCredentials['private_key']));

        // The void notification that follows changes nothing more.
        $this->sendWompiEvent($account, $this->wompiEvent($payment, 'VOIDED', self::cop(6000), timestamp: 1700000100))->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertSame(2, DB::table('payment_refunds')->where('payment_id', $payment)->count());
    }

    public function test_the_sync_job_retries_confirms_and_expires_links(): void
    {
        [$owner, , $lot, $session] = $this->owingStay();
        $account = $this->createProviderAccount($owner);
        $payment = $this->paymentLink($owner, 'parking_session', $session, $account)->assertCreated()->json('data.payment.id');

        $approved = ['data' => [['id' => 'tx-sync', 'status' => 'APPROVED', 'amount_in_cents' => self::cop(6000), 'reference' => $payment, 'currency' => 'COP', 'payment_method_type' => 'CARD']]];
        // Two failed attempts (the client retries once), then the approval; afterwards, no transactions.
        $answers = [Http::response([], 503), Http::response([], 503), Http::response($approved)];
        Http::fake(['sandbox.wompi.co/v1/transactions*' => function () use (&$answers) {
            return array_shift($answers) ?? Http::response(['data' => []]);
        }]);

        // Wompi is down: the payment stays pending and is asked again later.
        $this->travel(3)->minutes();
        $this->artisan('payments:sync')->assertSuccessful();
        $this->assertDatabaseHas('payments', ['id' => $payment, 'status' => 'pending', 'sync_attempts' => 1]);

        $this->travel(3)->minutes();
        $this->artisan('payments:sync')->assertSuccessful();
        $this->assertDatabaseHas('payments', ['id' => $payment, 'status' => 'completed', 'provider_reference' => 'tx-sync', 'method' => 'card']);

        // A link nobody paid is given up after it expires.
        $this->createSpace($owner, $lot, 'A-02');
        $second = $this->enter($owner, $lot, 'EXP123')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();
        $this->exitWith($owner, $second)->assertOk();
        $expiring = $this->paymentLink($owner, 'parking_session', $second, $account)->assertCreated()->json('data.payment.id');

        $this->travel(80)->minutes();
        $this->artisan('payments:sync')->assertSuccessful();
        $this->assertDatabaseHas('payments', ['id' => $expiring, 'status' => 'failed', 'failure_reason' => 'El enlace venció sin pago.']);

        // The stay can get a new link once the old one is over.
        $this->paymentLink($owner, 'parking_session', $second, $account)->assertCreated();
    }

    public function test_a_cashier_cancels_a_link_unless_it_was_already_paid(): void
    {
        [$owner, $tenant, , $session] = $this->owingStay();
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $account = $this->createProviderAccount($owner);
        $payment = $this->paymentLink($cashier, 'parking_session', $session, $account)->assertCreated()->json('data.payment.id');

        Http::fake(['sandbox.wompi.co/v1/transactions*' => Http::response(['data' => []])]);
        $this->actingAs($cashier)->postJson("/api/v1/payments/{$payment}/cancel")->assertOk()->assertJsonPath('data.payment.status', 'failed');

        // The customer pays cash instead; a late approval of the cancelled link is recorded and shows as overpaid.
        $this->pay($cashier, 'parking_session', $session)->assertCreated();
        $this->sendWompiEvent($account, $this->wompiEvent($payment, 'APPROVED', self::cop(6000)))->assertOk()->assertJsonPath('outcome', 'completed');
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}")->assertOk()->assertJsonPath('data.session.payment.status', 'overpaid');

        $this->paymentLink($cashier, 'parking_session', $session, $account)->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_PAID');
    }

    public function test_the_provider_reconciliation_fixes_what_it_can_and_reports_the_rest(): void
    {
        [$owner, $tenant, $lot, $session] = $this->owingStay();
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $account = $this->createProviderAccount($owner);
        $this->createSpace($owner, $lot, 'A-02');
        $second = $this->enter($owner, $lot, 'REC123')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();
        $this->exitWith($owner, $second)->assertOk();

        $pending = $this->paymentLink($owner, 'parking_session', $session, $account)->assertCreated()->json('data.payment.id');
        $received = $this->paymentLink($owner, 'parking_session', $second, $account)->assertCreated()->json('data.payment.id');
        $this->sendWompiEvent($account, $this->wompiEvent($received, 'APPROVED', self::cop(6000), 'tx-received'))->assertOk();

        Http::fake(['sandbox.wompi.co/v1/transactions*' => function (ClientRequest $request) use ($pending) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ($query['reference'] ?? null) {
                $pending => Http::response(['data' => [['id' => 'tx-late', 'status' => 'APPROVED', 'amount_in_cents' => self::cop(6000), 'reference' => $pending, 'currency' => 'COP']]]),
                default => Http::response(['data' => []]),
            };
        }, 'sandbox.wompi.co/v1/transactions/tx-received' => Http::response(['data' => ['id' => 'tx-received', 'status' => 'DECLINED', 'amount_in_cents' => self::cop(6000), 'reference' => $received, 'currency' => 'COP']])]);

        $this->actingAs($cashier)->postJson("/api/v1/payment-providers/{$account}/reconcile", ['from_date' => '2026-12-01', 'to_date' => '2026-12-01'])->assertForbidden();

        $result = $this->actingAs($owner)->postJson("/api/v1/payment-providers/{$account}/reconcile", ['from_date' => '2026-12-01', 'to_date' => '2026-12-01'])
            ->assertOk()
            ->assertJsonPath('data.reconciliation.checked', 2)
            ->assertJsonPath('data.reconciliation.fixed', 1)
            ->assertJsonPath('data.reconciliation.issues', 1)
            ->json('data.reconciliation.items');

        $byId = collect($result)->keyBy('payment_id');
        $this->assertTrue($byId[$pending]['fixed']);
        $this->assertSame('completed', $byId[$pending]['status']);
        $this->assertSame('status_mismatch', $byId[$received]['issue']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.provider_reconciled', 'resource_id' => $account]);
    }

    public function test_a_membership_period_left_unpaid_stops_covering_after_the_grace_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createTariff($owner, $lot);
        $plan = $this->createPlan($owner);
        $membership = $this->sell($owner, $plan, $this->createCustomer($owner), ['MPG123'])->assertCreated()->json('data.membership.id');

        // Within the three grace days from the first day the member is covered.
        $first = $this->enter($owner, $lot, 'MPG123')->assertCreated()->assertJsonPath('data.notices.0.type', 'info')->json('data.session.id');
        $this->exitWith($owner, $first)->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 08:00:00', 'America/Bogota'));
        $unpaid = $this->enter($owner, $lot, 'MPG123')->assertCreated()
            ->assertJsonPath('data.session.membership', null)
            ->assertJsonPath('data.notices.0.type', 'warning');
        $this->assertStringContainsString('no está pagado', (string) $unpaid->json('data.notices.0.message'));
        $this->actingAs($owner)->postJson('/api/v1/parking-sessions/'.$unpaid->json('data.session.id').'/cancel', ['reason' => 'Prueba de cobertura'])->assertOk();

        $this->payPeriods($owner, $membership);
        $this->enter($owner, $lot, 'MPG123')->assertCreated()->assertJsonPath('data.notices.0.type', 'info');
    }
}
