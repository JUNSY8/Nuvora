<?php

namespace Tests\Unit;

use App\Modules\Payments\Domain\CashTender;
use App\Modules\Payments\Domain\PaymentBalance;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Domain\PaymentRuleViolation;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Domain\ReceiptSeries;
use PHPUnit\Framework\TestCase;

class PaymentRulesTest extends TestCase
{
    public function test_cash_returns_change_and_never_takes_less_than_the_charge(): void
    {
        $tender = CashTender::for(PaymentMethod::Cash, 600000, 1000000, 'COP');
        $this->assertSame(1000000, $tender->tendered);
        $this->assertSame(400000, $tender->change);

        $exact = CashTender::for(PaymentMethod::Cash, 600000, null, 'COP');
        $this->assertSame(600000, $exact->tendered);
        $this->assertSame(0, $exact->change);

        $card = CashTender::for(PaymentMethod::Card, 600000, 1000000, 'COP');
        $this->assertNull($card->tendered);
        $this->assertNull($card->change);

        $this->assertViolation('INSUFFICIENT_TENDER', fn () => CashTender::for(PaymentMethod::Cash, 600000, 500000, 'COP'));
        // Pesos are handed over whole.
        $this->assertViolation('INVALID_AMOUNT', fn () => CashTender::for(PaymentMethod::Cash, 600000, 600050, 'COP'));
    }

    public function test_refunds_move_the_status_and_never_exceed_the_payment(): void
    {
        $this->assertSame(PaymentStatus::PartiallyRefunded, PaymentStatus::Completed->afterRefund(600000, 0, 200000));
        $this->assertSame(PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded->afterRefund(600000, 200000, 400000));
        $this->assertSame(PaymentStatus::Refunded, PaymentStatus::Completed->afterRefund(600000, 0, 600000));

        $this->assertViolation('REFUND_EXCEEDS_PAYMENT', fn () => PaymentStatus::PartiallyRefunded->afterRefund(600000, 200000, 400100));
        $this->assertViolation('INVALID_AMOUNT', fn () => PaymentStatus::Completed->afterRefund(600000, 0, 0));
        $this->assertViolation('PAYMENT_NOT_REFUNDABLE', fn () => PaymentStatus::Refunded->afterRefund(600000, 600000, 100));
        $this->assertViolation('PAYMENT_NOT_REFUNDABLE', fn () => PaymentStatus::Pending->afterRefund(600000, 0, 100));
        $this->assertViolation('PAYMENT_NOT_REFUNDABLE', fn () => PaymentStatus::Failed->afterRefund(600000, 0, 100));

        $this->assertSame(['completed', 'partially_refunded', 'refunded'], PaymentStatus::collectedValues());
        $this->assertFalse(PaymentStatus::Pending->isCollected());
    }

    public function test_a_balance_reports_what_is_still_owed(): void
    {
        $this->assertSame(['status' => 'not_required', 'due' => 0, 'paid' => 0, 'balance' => 0], (new PaymentBalance(0, 0))->toArray());
        $this->assertSame(['status' => 'pending', 'due' => 600000, 'paid' => 0, 'balance' => 600000], (new PaymentBalance(600000, 0))->toArray());
        $this->assertSame(['status' => 'partial', 'due' => 600000, 'paid' => 200000, 'balance' => 400000], (new PaymentBalance(600000, 200000))->toArray());
        $this->assertSame(['status' => 'paid', 'due' => 600000, 'paid' => 600000, 'balance' => 0], (new PaymentBalance(600000, 600000))->toArray());
        $this->assertTrue((new PaymentBalance(600000, 600000))->isSettled());
        $this->assertFalse((new PaymentBalance(0, 0))->isSettled());
    }

    public function test_receipt_numbers_are_padded_per_series(): void
    {
        $this->assertSame('RC-000001', ReceiptSeries::Payment->format(1));
        $this->assertSame('DV-000042', ReceiptSeries::Refund->format(42));
        $this->assertSame('CC-1234567', ReceiptSeries::Reconciliation->format(1234567));
    }

    private function assertViolation(string $code, callable $action): void
    {
        try {
            $action();
        } catch (PaymentRuleViolation $violation) {
            $this->assertSame($code, $violation->errorCode);

            return;
        }

        $this->fail("Expected {$code}.");
    }
}
