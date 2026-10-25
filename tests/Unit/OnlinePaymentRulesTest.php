<?php

namespace Tests\Unit;

use App\Modules\Memberships\Domain\AccessDecision;
use App\Modules\Memberships\Domain\AccessRules;
use App\Modules\Payments\Domain\Gateway\CheckoutRequest;
use App\Modules\Payments\Domain\Gateway\InvalidProviderSignature;
use App\Modules\Payments\Domain\Gateway\ProviderAccount;
use App\Modules\Payments\Domain\Gateway\ProviderTransaction;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\ProviderStatus;
use App\Modules\Payments\Infrastructure\Providers\PayUProvider;
use App\Modules\Payments\Infrastructure\Providers\WompiProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class OnlinePaymentRulesTest extends TestCase
{
    public function test_provider_answers_never_undo_a_final_status(): void
    {
        $this->assertSame(PaymentStatus::Completed, PaymentStatus::Pending->afterProvider(ProviderStatus::Approved));
        $this->assertSame(PaymentStatus::Failed, PaymentStatus::Pending->afterProvider(ProviderStatus::Declined));
        $this->assertSame(PaymentStatus::Failed, PaymentStatus::Pending->afterProvider(ProviderStatus::Expired));
        // A late approval still counts: the money was taken.
        $this->assertSame(PaymentStatus::Completed, PaymentStatus::Failed->afterProvider(ProviderStatus::Approved));
        $this->assertNull(PaymentStatus::Pending->afterProvider(ProviderStatus::Pending));
        $this->assertNull(PaymentStatus::Completed->afterProvider(ProviderStatus::Pending));
        $this->assertNull(PaymentStatus::Completed->afterProvider(ProviderStatus::Declined));
        $this->assertNull(PaymentStatus::Completed->afterProvider(ProviderStatus::Approved));

        $this->assertSame(PaymentStatus::Completed, PaymentStatus::forRefunded(600000, 0));
        $this->assertSame(PaymentStatus::PartiallyRefunded, PaymentStatus::forRefunded(600000, 100));
        $this->assertSame(PaymentStatus::Refunded, PaymentStatus::forRefunded(600000, 600000));
    }

    public function test_provider_payment_types_map_to_nuvora_methods(): void
    {
        $transaction = fn (?string $type): PaymentMethod => (new ProviderTransaction('r', 't', ProviderStatus::Approved, 1, 'COP', $type))->method();

        $this->assertSame(PaymentMethod::Card, $transaction('CARD'));
        $this->assertSame(PaymentMethod::Card, $transaction('CREDIT_CARD'));
        $this->assertSame(PaymentMethod::Transfer, $transaction('PSE'));
        $this->assertSame(PaymentMethod::Transfer, $transaction('NEQUI'));
        $this->assertSame(PaymentMethod::Other, $transaction(null));
    }

    public function test_wompi_signs_the_checkout_and_verifies_events(): void
    {
        $account = new ProviderAccount('acc', 'ten', 'wompi', true, ['public_key' => 'pub_test_1', 'private_key' => 'prv_test_1', 'events_secret' => 'evt_secret', 'integrity_secret' => 'int_secret']);
        $wompi = new WompiProvider;
        $checkout = $wompi->checkout($account, new CheckoutRequest('ref-1', 600000, 'COP', 'Estancia', 'https://app/return', 'https://api/hook', new DateTimeImmutable('2026-12-01T14:00:00Z')));

        $this->assertSame('GET', $checkout->method);
        $this->assertSame('600000', $checkout->fields['amount-in-cents']);
        $this->assertSame('2026-12-01T14:00:00.000Z', $checkout->fields['expiration-time']);
        $this->assertSame(hash('sha256', 'ref-1600000COP2026-12-01T14:00:00.000Zint_secret'), $checkout->fields['signature:integrity']);
        $this->assertArrayNotHasKey('private_key', $checkout->fields);

        $event = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => ['id' => 'tx-1', 'status' => 'APPROVED', 'amount_in_cents' => 600000, 'reference' => 'ref-1', 'currency' => 'COP', 'payment_method_type' => 'NEQUI']],
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => hash('sha256', 'tx-1APPROVED6000001700000000evt_secret')],
            'timestamp' => 1700000000,
        ];

        $notification = $wompi->parseNotification($account, [], (string) json_encode($event));
        $this->assertSame(ProviderStatus::Approved, $notification->transaction?->status);
        $this->assertSame('ref-1', $notification->transaction?->reference);
        $this->assertSame(600000, $notification->transaction?->amount);

        $event['data']['transaction']['amount_in_cents'] = 100;
        $this->expectException(InvalidProviderSignature::class);
        $wompi->parseNotification($account, [], (string) json_encode($event));
    }

    public function test_payu_signs_the_form_and_verifies_confirmations(): void
    {
        $account = new ProviderAccount('acc', 'ten', 'payu', true, ['merchant_id' => '508029', 'account_id' => '512321', 'api_key' => 'KEY', 'api_login' => 'LOGIN']);
        $payu = new PayUProvider;
        $checkout = $payu->checkout($account, new CheckoutRequest('ref-2', 600000, 'COP', 'Mensualidad', 'https://app/return', 'https://api/hook', new DateTimeImmutable('2026-12-01T14:00:00Z')));

        $this->assertSame('POST', $checkout->method);
        $this->assertSame('6000.00', $checkout->fields['amount']);
        $this->assertSame(md5('KEY~508029~ref-2~6000.00~COP'), $checkout->fields['signature']);
        $this->assertSame('1', $checkout->fields['test']);

        $this->assertSame('6000.0', PayUProvider::newValue('6000.00'));
        $this->assertSame('150.26', PayUProvider::newValue('150.26'));
        $this->assertSame('150.2', PayUProvider::newValue('150.20'));
        $this->assertSame(600000, PayUProvider::minor('6000.00', 'COP'));

        $form = ['merchant_id' => '508029', 'reference_sale' => 'ref-2', 'value' => '6000.00', 'currency' => 'COP', 'state_pol' => '4', 'transaction_id' => 'tx-9', 'reference_pol' => '8801', 'payment_method_type' => '4'];
        $form['sign'] = md5('KEY~508029~ref-2~6000.0~COP~4');

        $notification = $payu->parseNotification($account, [], http_build_query($form));
        $this->assertSame(ProviderStatus::Approved, $notification->transaction?->status);
        $this->assertSame('PSE', $notification->transaction?->methodType);
        $this->assertSame('8801', $notification->transaction?->data['order_id']);
        $this->assertSame('tx-9-4', $notification->eventKey);

        $form['state_pol'] = '6';
        $this->expectException(InvalidProviderSignature::class);
        $payu->parseNotification($account, [], http_build_query($form));
    }

    public function test_an_unpaid_period_stops_covering_after_the_grace_days(): void
    {
        $start = new DateTimeImmutable('2026-11-01');
        $end = new DateTimeImmutable('2026-11-30');
        $covered = AccessDecision::covered();

        $this->assertTrue(AccessRules::requirePayment($covered, 'due', $start, $end, 3, new DateTimeImmutable('2026-11-04 20:00'))->covered);
        $late = AccessRules::requirePayment($covered, 'due', $start, $end, 3, new DateTimeImmutable('2026-11-05 07:00'));
        $this->assertFalse($late->covered);
        $this->assertSame('MEMBERSHIP_UNPAID', $late->code);
        $this->assertTrue(AccessRules::requirePayment($covered, 'paid', $start, $end, 3, new DateTimeImmutable('2026-11-20'))->covered);
        // An arrival already denied for another reason keeps that reason.
        $this->assertSame('MEMBERSHIP_SUSPENDED', AccessRules::requirePayment(AccessDecision::denied('MEMBERSHIP_SUSPENDED', 'x'), 'due', $start, $end, 3, new DateTimeImmutable('2026-11-20'))->code);
    }
}
