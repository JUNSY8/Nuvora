<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each tenant collects into its own provider account; secrets are encrypted by the model.
        Schema::create('payment_provider_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('provider', 20);
            $table->string('name', 80);
            $table->string('environment', 20);
            $table->string('status', 20);
            $table->text('credentials');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            // Online payments get their receipt number when the provider confirms them.
            $table->string('number', 20)->nullable()->change();
            $table->ulid('provider_account_id')->nullable();
            $table->string('provider_method', 40)->nullable();
            $table->json('provider_data')->nullable();
            $table->string('checkout_token_hash', 64)->nullable();
            $table->text('checkout_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->timestamp('last_synced_at')->nullable();

            $table->foreign(['tenant_id', 'provider_account_id'])->references(['tenant_id', 'id'])->on('payment_provider_accounts')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('checkout_token_hash');
            $table->index(['status', 'provider', 'last_synced_at']);
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            // An online refund may wait for the provider; offline refunds complete at once.
            $table->string('status', 20)->default('completed');
            $table->string('provider', 30)->default('offline');
            $table->string('failure_reason', 255)->nullable();
            $table->index(['status', 'provider']);
        });

        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('provider_account_id');
            $table->string('provider', 20);
            // Identifies the notification itself, so a retried webhook is applied once.
            $table->string('event_key', 128);
            $table->string('event_type', 60);
            $table->string('reference', 100)->nullable();
            $table->ulid('payment_id')->nullable();
            $table->string('status', 20);
            $table->string('outcome', 255)->nullable();
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'provider_account_id'])->references(['tenant_id', 'id'])->on('payment_provider_accounts')->cascadeOnDelete();
            $table->unique(['provider_account_id', 'event_key']);
            $table->index(['tenant_id', 'payment_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE payment_provider_accounts ADD CONSTRAINT payment_provider_accounts_values_check CHECK (
                    provider IN ('wompi', 'payu') AND environment IN ('sandbox', 'production') AND status IN ('active', 'inactive')
                );

                ALTER TABLE payments ADD CONSTRAINT payments_online_check CHECK (
                    (provider = 'offline') = (provider_account_id IS NULL)
                    AND (status NOT IN ('completed', 'partially_refunded', 'refunded') OR number IS NOT NULL)
                    AND (provider = 'offline' OR (tendered_amount IS NULL AND received_by IS NULL))
                );

                ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_status_check CHECK (
                    status IN ('pending', 'completed', 'failed')
                );

                ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_events_values_check CHECK (
                    status IN ('received', 'processed', 'ignored', 'failed')
                );
            SQL);

            foreach (['payment_provider_accounts', 'payment_provider_events'] as $table) {
                DB::unprepared(<<<SQL
                    ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;

                    CREATE POLICY {$table}_tenant_context ON {$table}
                        USING (
                            current_setting('nuvora.tenant_id', true) IS NULL
                            OR btrim(current_setting('nuvora.tenant_id', true)) = ''
                            OR tenant_id::text = current_setting('nuvora.tenant_id', true)
                        )
                        WITH CHECK (
                            current_setting('nuvora.tenant_id', true) IS NULL
                            OR btrim(current_setting('nuvora.tenant_id', true)) = ''
                            OR tenant_id::text = current_setting('nuvora.tenant_id', true)
                        );
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_online_check');
            DB::statement("DELETE FROM payment_refunds WHERE payment_id IN (SELECT id FROM payments WHERE provider <> 'offline')");
            DB::statement("DELETE FROM payments WHERE provider <> 'offline'");
        }

        Schema::dropIfExists('payment_provider_events');

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropIndex(['status', 'provider']);
            $table->dropColumn(['status', 'provider', 'failure_reason']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'provider_account_id']);
            $table->dropForeign(['requested_by']);
            $table->dropUnique(['checkout_token_hash']);
            $table->dropIndex(['status', 'provider', 'last_synced_at']);
            $table->dropColumn(['provider_account_id', 'provider_method', 'provider_data', 'checkout_token_hash', 'checkout_token', 'expires_at', 'requested_by', 'failure_reason', 'sync_attempts', 'last_synced_at']);
        });

        Schema::dropIfExists('payment_provider_accounts');
    }
};
