<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Persistence\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCustomers;
use Tests\Concerns\InteractsWithMemberships;
use Tests\Concerns\InteractsWithParking;
use Tests\Concerns\InteractsWithParkingSessions;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTariffs;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use InteractsWithCustomers;
    use InteractsWithMemberships;
    use InteractsWithParking;
    use InteractsWithParkingSessions;
    use InteractsWithPayments;
    use InteractsWithTariffs;
    use InteractsWithTenants;
    use RefreshDatabase;

    public function test_cash_at_the_exit_returns_change_settles_the_stay_and_prints_a_receipt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createTariff($owner, $lot);
        $session = $this->enter($owner, $lot, 'ABC123')->assertCreated()->json('data.session.id');

        // 1 h 30 min at $3.000 per started hour.
        $this->travel(90)->minutes();
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}/quote")
            ->assertOk()
            ->assertJsonPath('data.quote.total', self::cop(6000))
            ->assertJsonStructure(['data' => ['quote' => ['quote_token', 'quote_expires_at', 'priced_at']]]);

        $exit = $this->exitWith($owner, $session, ['payment' => ['method' => 'cash', 'tendered_amount' => self::cop(10000)]], ['Idempotency-Key' => 'exit-abc123-0001'])
            ->assertOk()
            ->assertJsonPath('data.payment.number', 'RC-000001')
            ->assertJsonPath('data.payment.amount', self::cop(6000))
            ->assertJsonPath('data.payment.tendered_amount', self::cop(10000))
            ->assertJsonPath('data.payment.change_amount', self::cop(4000))
            ->assertJsonPath('data.session.payment.status', 'paid')
            ->assertJsonPath('data.session.payment.balance', 0);
        $paymentId = $exit->json('data.payment.id');

        // A retried exit returns the closed stay and does not collect twice.
        $this->exitWith($owner, $session, ['payment' => ['method' => 'cash', 'tendered_amount' => self::cop(10000)]], ['Idempotency-Key' => 'exit-abc123-0001'])
            ->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, DB::table('payments')->count());

        $this->actingAs($owner)->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.number', 'RC-000001')
            ->assertJsonPath('data.items.0.plate', 'ABC123')
            ->assertJsonPath('data.items.0.parking_lot.code', 'CENTRO')
            ->assertJsonPath('data.totals.collected', self::cop(6000))
            ->assertJsonPath('data.totals.by_method.cash.count', 1);

        $this->actingAs($owner)->getJson("/api/v1/payments/{$paymentId}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt.number', 'RC-000001')
            ->assertJsonPath('data.receipt.tenant.name', 'Parqueadero Central')
            ->assertJsonPath('data.receipt.parking_lot.name', 'Sede Centro')
            ->assertJsonPath('data.receipt.details.plate', 'ABC123')
            ->assertJsonPath('data.receipt.details.duration_minutes', 90)
            ->assertJsonPath('data.receipt.method_label', 'Efectivo')
            ->assertJsonPath('data.receipt.change', self::cop(4000));

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()
            ->assertJsonPath('data.charged_today', self::cop(6000))
            ->assertJsonPath('data.collected_today', self::cop(6000))
            ->assertJsonPath('data.unpaid_today', 0);

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.collected', 'resource_id' => $paymentId]);
    }

    public function test_cash_that_does_not_cover_the_charge_leaves_the_vehicle_inside(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $space = $this->createSpace($owner, $lot, 'A-01');
        $this->createTariff($owner, $lot);
        $session = $this->enter($owner, $lot, 'POC123')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();

        $this->exitWith($owner, $session, ['payment' => ['method' => 'cash', 'tendered_amount' => self::cop(5000)]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INSUFFICIENT_TENDER');
        $this->exitWith($owner, $session, ['payment' => ['method' => 'cash', 'tendered_amount' => self::cop(6000) + 50]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_AMOUNT');

        $this->assertSame('open', DB::table('parking_sessions')->where('id', $session)->value('status'));
        $this->assertSame('occupied', $this->spaceStatus($space));
        $this->assertSame(0, DB::table('payments')->count());

        // A card payment collects exactly the charge; no change is involved.
        $this->exitWith($owner, $session, ['payment' => ['method' => 'card', 'reference' => 'APR-7781']])
            ->assertOk()
            ->assertJsonPath('data.payment.method', 'card')
            ->assertJsonPath('data.payment.change_amount', null);
        $this->assertDatabaseHas('payments', ['payable_id' => $session, 'method' => 'card', 'reference' => 'APR-7781', 'tendered_amount' => null]);
    }

    public function test_a_quote_holds_its_price_for_a_few_minutes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');
        $this->createTariff($owner, $lot);
        $held = $this->enter($owner, $lot, 'HLD001')->assertCreated()->json('data.session.id');
        $late = $this->enter($owner, $lot, 'HLD002')->assertCreated()->json('data.session.id');

        $this->travel(58)->minutes();
        $heldQuote = $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$held}/quote")
            ->assertOk()->assertJsonPath('data.quote.total', self::cop(3000))->json('data.quote.quote_token');
        $lateQuote = $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$late}/quote")
            ->assertOk()->json('data.quote.quote_token');

        // Another session's token, or a different discount, is not accepted.
        $this->exitWith($owner, $held, ['quote_token' => $lateQuote])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_QUOTE');
        $this->exitWith($owner, $held, ['quote_token' => 'not-a-token'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_QUOTE');

        // Four minutes later the stay crossed the hour, but the quote still holds.
        $this->travel(4)->minutes();
        $this->exitWith($owner, $held, ['quote_token' => $heldQuote, 'payment' => ['method' => 'cash', 'tendered_amount' => self::cop(3000)]])
            ->assertOk()
            ->assertJsonPath('data.session.duration_minutes', 62)
            ->assertJsonPath('data.session.charge.total', self::cop(3000))
            ->assertJsonPath('data.payment.change_amount', 0);

        // After the hold expires the exit prices at its own moment.
        $this->travel(5)->minutes();
        $this->exitWith($owner, $late, ['quote_token' => $lateQuote])
            ->assertOk()
            ->assertJsonPath('data.session.charge.total', self::cop(6000))
            ->assertJsonPath('data.session.payment.status', 'pending');
    }

    public function test_a_stay_can_be_paid_later_in_parts_but_never_beyond_its_charge(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');
        $this->createTariff($owner, $lot);
        $session = $this->enter($owner, $lot, 'PRT123')->assertCreated()->json('data.session.id');
        $open = $this->enter($owner, $lot, 'OPN123')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();

        $this->exitWith($owner, $session)->assertOk()
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.session.payment.status', 'pending')
            ->assertJsonPath('data.session.payment.balance', self::cop(6000));

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions?unpaid=1&lot_id={$lot}")
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $session);
        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/summary?lot_id={$lot}")
            ->assertOk()->assertJsonPath('data.unpaid_today', 1)->assertJsonPath('data.collected_today', 0);

        $key = ['Idempotency-Key' => 'pay-prt123-0001'];
        $first = $this->pay($owner, 'parking_session', $session, ['method' => 'transfer', 'amount' => self::cop(2000), 'reference' => 'NEQUI-1'], $key)
            ->assertCreated()
            ->assertJsonPath('data.payment.amount', self::cop(2000))
            ->json('data.payment.id');
        $this->pay($owner, 'parking_session', $session, ['method' => 'transfer', 'amount' => self::cop(2000)], $key)
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.payment.id', $first);
        $this->pay($owner, 'parking_session', $open, [], $key)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}")
            ->assertOk()->assertJsonPath('data.session.payment.status', 'partial')->assertJsonPath('data.session.payment.balance', self::cop(4000));

        $this->pay($owner, 'parking_session', $session, ['amount' => self::cop(5000)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'AMOUNT_EXCEEDS_BALANCE');
        // Without an amount it pays the rest.
        $this->pay($owner, 'parking_session', $session, ['tendered_amount' => self::cop(5000)])
            ->assertCreated()->assertJsonPath('data.payment.amount', self::cop(4000))->assertJsonPath('data.payment.change_amount', self::cop(1000));
        $this->pay($owner, 'parking_session', $session)
            ->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_PAID');
        $this->pay($owner, 'parking_session', $open)
            ->assertStatus(409)->assertJsonPath('error.code', 'NOTHING_DUE');

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions?unpaid=1&lot_id={$lot}")->assertOk()->assertJsonCount(0, 'data.items');
        $this->actingAs($owner)->getJson("/api/v1/payments?payable_type=parking_session&payable_id={$session}")
            ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.totals.net', self::cop(6000));
    }

    public function test_free_and_covered_stays_owe_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');
        $this->createTariff($owner, $lot);
        $plan = $this->createPlan($owner);
        $this->sell($owner, $plan, $this->createCustomer($owner), ['MEM123'], ['starts_on' => '2026-12-01'])->assertCreated();

        $grace = $this->enter($owner, $lot, 'GRA123')->assertCreated()->json('data.session.id');
        $member = $this->enter($owner, $lot, 'MEM123')->assertCreated()->json('data.session.id');
        $this->travel(10)->minutes();

        // Paying with the exit is skipped when nothing is due.
        $this->exitWith($owner, $grace, ['payment' => ['method' => 'cash']])
            ->assertOk()->assertJsonPath('data.payment', null)->assertJsonPath('data.session.payment.status', 'not_required');
        $this->travel(2)->hours();
        $this->exitWith($owner, $member, ['payment' => ['method' => 'cash']])
            ->assertOk()->assertJsonPath('data.session.charge.status', 'covered')->assertJsonPath('data.session.payment.status', 'not_required');

        $this->pay($owner, 'parking_session', $member)->assertStatus(409)->assertJsonPath('error.code', 'NOTHING_DUE');
        $this->pay($owner, 'parking_session', $grace)->assertStatus(409)->assertJsonPath('error.code', 'NOTHING_DUE');
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_refunds_give_money_back_and_reopen_the_balance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner();
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createTariff($owner, $lot);
        $session = $this->enter($owner, $lot, 'REF123')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();
        $payment = $this->exitWith($cashier, $session, ['payment' => ['method' => 'cash']])->assertOk()->json('data.payment.id');

        // A cashier collects but does not refund.
        $this->refund($cashier, $payment)->assertForbidden();
        $this->refund($owner, $payment, ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'error.details');

        $key = ['Idempotency-Key' => 'refund-ref123-0001'];
        $this->refund($owner, $payment, ['amount' => self::cop(2000)], $key)
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'partially_refunded')
            ->assertJsonPath('data.payment.refunded_amount', self::cop(2000))
            ->assertJsonPath('data.payment.net_amount', self::cop(4000))
            ->assertJsonPath('data.payment.refunds.0.number', 'DV-000001')
            ->assertJsonPath('data.payment.refunds.0.reason', 'Cobro duplicado');
        $this->refund($owner, $payment, ['amount' => self::cop(2000)], $key)
            ->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, DB::table('payment_refunds')->count());

        $this->actingAs($owner)->getJson("/api/v1/parking-sessions/{$session}")
            ->assertOk()->assertJsonPath('data.session.payment.status', 'partial')->assertJsonPath('data.session.payment.balance', self::cop(2000));

        $this->refund($owner, $payment, ['amount' => self::cop(5000)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'REFUND_EXCEEDS_PAYMENT');
        $this->refund($owner, $payment)
            ->assertCreated()->assertJsonPath('data.payment.status', 'refunded')->assertJsonPath('data.payment.net_amount', 0);
        $this->refund($owner, $payment)
            ->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_NOT_REFUNDABLE');

        // The stay owes its whole charge again and can be collected anew.
        $this->pay($cashier, 'parking_session', $session)->assertCreated()->assertJsonPath('data.payment.number', 'RC-000002');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.refunded', 'resource_id' => $payment]);
    }

    public function test_membership_periods_are_collected_and_marked_paid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $plan = $this->createPlan($owner);
        $membership = $this->sell($owner, $plan, $this->createCustomer($owner), ['MEN123'], ['starts_on' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('data.membership.periods.0.status', 'due')
            ->assertJsonPath('data.membership.periods.0.balance', 12000000)
            ->assertJsonPath('data.membership.outstanding', 12000000)
            ->json('data.membership');
        $period = $membership['periods'][0]['id'];

        $payment = $this->pay($owner, 'membership_period', $period, ['method' => 'transfer', 'reference' => 'BANCO-55'])
            ->assertCreated()
            ->assertJsonPath('data.payment.amount', 12000000)
            ->assertJsonPath('data.payment.details.membership_code', $membership['code'])
            ->assertJsonPath('data.payment.details.customer', 'Laura Gómez')
            ->json('data.payment.id');

        $this->assertDatabaseHas('membership_periods', ['id' => $period, 'status' => 'paid']);
        $this->actingAs($owner)->getJson("/api/v1/memberships/{$membership['id']}")
            ->assertOk()
            ->assertJsonPath('data.membership.periods.0.status', 'paid')
            ->assertJsonPath('data.membership.periods.0.paid', 12000000)
            ->assertJsonPath('data.membership.outstanding', 0);

        $this->refund($owner, $payment, ['amount' => 6000000])->assertCreated();
        $this->assertDatabaseHas('membership_periods', ['id' => $period, 'status' => 'due']);

        // A renewal opens a new period to collect.
        $this->actingAs($owner)->postJson("/api/v1/memberships/{$membership['id']}/renew")
            ->assertOk()->assertJsonPath('data.membership.outstanding', 18000000);
    }

    public function test_a_cash_closing_takes_each_movement_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner();
        $cashier = User::factory()->create();
        $this->addMember($owner, $tenant, $cashier, 'cashier');
        $lot = $this->createLot($owner);
        foreach (['A-01', 'A-02', 'A-03'] as $code) {
            $this->createSpace($owner, $lot, $code);
        }
        $this->createTariff($owner, $lot);
        $first = $this->enter($owner, $lot, 'CAJ001')->assertCreated()->json('data.session.id');
        $second = $this->enter($owner, $lot, 'CAJ002')->assertCreated()->json('data.session.id');
        $third = $this->enter($owner, $lot, 'CAJ003')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();

        $cash = $this->exitWith($cashier, $first, ['payment' => ['method' => 'cash', 'tendered_amount' => self::cop(10000)]])->assertOk()->json('data.payment.id');
        $this->exitWith($cashier, $second, ['payment' => ['method' => 'cash']])->assertOk();
        $this->exitWith($cashier, $third, ['payment' => ['method' => 'card', 'reference' => 'APR-1']])->assertOk();
        // The owner gives back $1.000 from the first payment: it leaves the owner's drawer.
        $this->refund($owner, $cash, ['amount' => self::cop(1000)])->assertCreated();

        $this->actingAs($cashier)->getJson('/api/v1/payment-reconciliations/preview')
            ->assertOk()
            ->assertJsonPath('data.currencies.0.currency', 'COP')
            ->assertJsonPath('data.currencies.0.payments_count', 3)
            ->assertJsonPath('data.currencies.0.refunds_count', 0)
            ->assertJsonPath('data.currencies.0.by_method.cash.collected', self::cop(12000))
            ->assertJsonPath('data.currencies.0.by_method.card.collected', self::cop(6000))
            ->assertJsonPath('data.currencies.0.expected_cash', self::cop(12000));

        // A cashier closes their own cash only.
        $this->actingAs($cashier)->postJson('/api/v1/payment-reconciliations', ['cashier_id' => $owner->id, 'currency' => 'COP', 'counted_cash' => 0])
            ->assertForbidden();
        $this->actingAs($cashier)->postJson('/api/v1/payment-reconciliations', ['currency' => 'COP', 'counted_cash' => self::cop(11500), 'notes' => 'Faltan $500'])
            ->assertCreated()
            ->assertJsonPath('data.reconciliation.number', 'CC-000001')
            ->assertJsonPath('data.reconciliation.payments_count', 3)
            ->assertJsonPath('data.reconciliation.expected_cash', self::cop(12000))
            ->assertJsonPath('data.reconciliation.counted_cash', self::cop(11500))
            ->assertJsonPath('data.reconciliation.difference', -self::cop(500));
        $this->actingAs($cashier)->postJson('/api/v1/payment-reconciliations', ['currency' => 'COP', 'counted_cash' => 0])
            ->assertUnprocessable()->assertJsonPath('error.code', 'NOTHING_TO_RECONCILE');

        $this->actingAs($owner)->getJson('/api/v1/payments?reconciled=0')->assertOk()->assertJsonCount(0, 'data.items');

        // The owner may close anyone's cash; their refund reduces the cash they should hold.
        $this->actingAs($owner)->postJson('/api/v1/payment-reconciliations', ['currency' => 'COP', 'counted_cash' => 0])
            ->assertCreated()
            ->assertJsonPath('data.reconciliation.refunds_count', 1)
            ->assertJsonPath('data.reconciliation.expected_cash', -self::cop(1000))
            ->assertJsonPath('data.reconciliation.difference', self::cop(1000));

        $this->actingAs($cashier)->getJson('/api/v1/payment-reconciliations')
            ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.1.cashier.id', $cashier->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reconciled']);
    }

    public function test_payments_are_isolated_by_tenant_and_follow_the_role(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner, $tenant] = $this->createTenantOwner();
        [$other] = $this->createTenantOwner('Otra empresa');
        $operator = User::factory()->create();
        $this->addMember($owner, $tenant, $operator, 'parking_operator');
        $accountant = User::factory()->create();
        $this->addMember($owner, $tenant, $accountant, 'accountant');
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createSpace($owner, $lot, 'A-02');
        $this->createTariff($owner, $lot);
        $paid = $this->enter($owner, $lot, 'ISO001')->assertCreated()->json('data.session.id');
        $owing = $this->enter($owner, $lot, 'ISO002')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();
        $payment = $this->exitWith($owner, $paid, ['payment' => ['method' => 'cash']])->assertOk()->json('data.payment.id');

        // An operator closes stays but does not collect: the exit with a payment is refused as a whole.
        $this->exitWith($operator, $owing, ['payment' => ['method' => 'cash']])->assertForbidden();
        $this->assertSame('open', DB::table('parking_sessions')->where('id', $owing)->value('status'));
        $this->exitWith($operator, $owing)->assertOk()->assertJsonPath('data.session.payment.status', 'pending');
        $this->actingAs($operator)->getJson('/api/v1/payments')->assertForbidden();

        $this->actingAs($accountant)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(1, 'data.items');
        $this->pay($accountant, 'parking_session', $owing)->assertForbidden();
        $this->actingAs($accountant)->getJson('/api/v1/payment-reconciliations/preview')->assertForbidden();

        // Another tenant can neither see nor touch these payments and stays.
        $this->actingAs($other)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.totals.collected', 0);
        $this->actingAs($other)->getJson("/api/v1/payments/{$payment}")->assertNotFound();
        $this->actingAs($other)->getJson("/api/v1/payments/{$payment}/receipt")->assertNotFound();
        $this->refund($other, $payment)->assertNotFound();
        $this->pay($other, 'parking_session', $owing)->assertNotFound();

        // Each tenant numbers its own receipts.
        $otherLot = $this->createLot($other, ['code' => 'otra']);
        $this->createSpace($other, $otherLot, 'B-01');
        $this->createTariff($other, $otherLot);
        $foreign = $this->enter($other, $otherLot, 'OTR001')->assertCreated()->json('data.session.id');
        $this->travel(30)->minutes();
        $this->exitWith($other, $foreign, ['payment' => ['method' => 'cash']])->assertOk()->assertJsonPath('data.payment.number', 'RC-000001');
        $this->assertSame(2, DB::table('payments')->count());
    }

    public function test_the_database_rejects_refunding_more_than_was_paid(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The check constraints exist only on PostgreSQL.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'America/Bogota'));
        [$owner] = $this->createTenantOwner();
        $lot = $this->createLot($owner);
        $this->createSpace($owner, $lot, 'A-01');
        $this->createTariff($owner, $lot);
        $session = $this->enter($owner, $lot, 'CHK123')->assertCreated()->json('data.session.id');
        $this->travel(90)->minutes();
        $payment = $this->exitWith($owner, $session, ['payment' => ['method' => 'cash']])->assertOk()->json('data.payment.id');

        $this->expectException(QueryException::class);

        // Simulates a writer that skipped the application rules.
        DB::table('payments')->where('id', $payment)->update(['refunded_amount' => self::cop(7000), 'status' => 'refunded']);
    }
}
