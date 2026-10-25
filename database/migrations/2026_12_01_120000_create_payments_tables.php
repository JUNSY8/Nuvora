<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consecutive receipt numbers per tenant and series (payments, refunds, cash closings).
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->string('series', 20);
            $table->unsignedBigInteger('last_number')->default(0);

            $table->primary(['tenant_id', 'series']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('number', 20);
            $table->ulid('cashier_id')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->char('currency', 3);
            $table->timestamp('first_movement_at')->nullable();
            $table->timestamp('closed_at');
            $table->unsignedInteger('payments_count');
            $table->unsignedInteger('refunds_count');
            // Collected, refunded and net per method, in minor units.
            $table->json('totals');
            $table->bigInteger('expected_cash');
            $table->bigInteger('counted_cash');
            $table->bigInteger('difference');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('cashier_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'closed_at']);
            $table->index(['tenant_id', 'cashier_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('number', 20);
            // What was paid: a parking session charge or a membership period. Each
            // module owns its table, so there is no foreign key; Payments reaches
            // them through the PayableSource contract.
            $table->string('payable_type', 30);
            $table->ulid('payable_id');
            $table->ulid('parking_lot_id')->nullable();
            $table->ulid('customer_id')->nullable();
            $table->string('description', 160);
            $table->string('plate', 12)->nullable();
            // Snapshot for the receipt: it must not change if the payable changes later.
            $table->json('details');
            $table->string('method', 20);
            $table->string('provider', 30);
            $table->string('provider_reference', 100)->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->char('currency', 3);
            $table->unsignedBigInteger('tendered_amount')->nullable();
            $table->unsignedBigInteger('change_amount')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->ulid('received_by')->nullable();
            $table->timestamp('received_at');
            $table->ulid('reconciliation_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reconciliation_id'])->references(['tenant_id', 'id'])->on('payment_reconciliations')->restrictOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'payable_type', 'payable_id']);
            $table->index(['tenant_id', 'received_at']);
            $table->index(['tenant_id', 'parking_lot_id', 'received_at']);
            $table->index(['tenant_id', 'received_by', 'reconciliation_id']);
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('payment_id');
            $table->string('number', 20);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            // Money goes back the way it came in.
            $table->string('method', 20);
            $table->string('provider_reference', 100)->nullable();
            $table->string('reason', 255);
            $table->string('idempotency_key', 80)->nullable();
            $table->ulid('refunded_by')->nullable();
            $table->timestamp('refunded_at');
            $table->ulid('reconciliation_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reconciliation_id'])->references(['tenant_id', 'id'])->on('payment_reconciliations')->restrictOnDelete();
            $table->foreign('refunded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'refunded_by', 'reconciliation_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE payments ADD CONSTRAINT payments_values_check CHECK (
                    payable_type IN ('parking_session', 'membership_period')
                    AND method IN ('cash', 'card', 'transfer', 'other')
                    AND status IN ('pending', 'completed', 'failed', 'partially_refunded', 'refunded')
                    AND currency ~ '^[A-Z]{3}$'
                    AND amount > 0
                    AND refunded_amount <= amount
                    AND (status <> 'completed' OR refunded_amount = 0)
                    AND (status <> 'partially_refunded' OR (refunded_amount > 0 AND refunded_amount < amount))
                    AND (status <> 'refunded' OR refunded_amount = amount)
                    AND (status NOT IN ('pending', 'failed') OR refunded_amount = 0)
                    AND (
                        (method = 'cash' AND tendered_amount >= amount AND change_amount = tendered_amount - amount)
                        OR (method <> 'cash' AND tendered_amount IS NULL AND change_amount IS NULL)
                    )
                );

                ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_values_check CHECK (
                    amount > 0 AND method IN ('cash', 'card', 'transfer', 'other') AND currency ~ '^[A-Z]{3}$'
                );

                ALTER TABLE payment_reconciliations ADD CONSTRAINT payment_reconciliations_values_check CHECK (
                    difference = counted_cash - expected_cash AND counted_cash >= 0 AND currency ~ '^[A-Z]{3}$'
                );
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('receipt_sequences');
    }
};
