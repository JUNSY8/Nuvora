<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target of the tariff's (lot, currency) reference: a lot cannot change
        // its currency while tariffs priced in it exist.
        Schema::table('parking_lots', function (Blueprint $table) {
            $table->unique(['id', 'currency']);
        });

        Schema::create('tariffs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->char('currency', 3);
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('status', 20);
            $table->unsignedInteger('current_version');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->foreign(['parking_lot_id', 'currency'])->references(['id', 'currency'])->on('parking_lots')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['parking_lot_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'parking_lot_id', 'status']);
        });

        // Every change to a tariff is a new immutable version. Sessions point to
        // the version that priced them, so a charge stays explainable.
        Schema::create('tariff_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('tariff_id');
            $table->ulid('parking_lot_id');
            $table->unsignedInteger('version');
            $table->timestamp('effective_from');
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('status', 20);
            $table->smallInteger('priority')->default(0);
            $table->json('vehicle_types');
            $table->ulid('zone_id')->nullable();
            $table->json('windows');
            $table->unsignedSmallInteger('grace_minutes')->nullable();
            $table->json('brackets');
            $table->unsignedInteger('step_minutes');
            $table->unsignedBigInteger('step_amount');
            $table->unsignedBigInteger('daily_max_amount')->nullable();
            $table->string('change_note', 255)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'tariff_id'])->references(['tenant_id', 'id'])->on('tariffs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->foreign(['parking_lot_id', 'zone_id'])->references(['parking_lot_id', 'id'])->on('parking_zones')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tariff_id', 'version']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['parking_lot_id', 'effective_from']);
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id')->nullable();
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('type', 20);
            $table->unsignedBigInteger('value');
            $table->char('currency', 3)->nullable();
            $table->string('application', 20);
            $table->string('status', 20);
            $table->json('vehicle_types');
            $table->json('days');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'application', 'status']);
        });

        // The charge computed at the exit. Amounts are minor units of currency.
        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->string('charge_status', 20)->nullable();
            $table->char('currency', 3)->nullable();
            $table->ulid('tariff_id')->nullable();
            $table->ulid('tariff_version_id')->nullable();
            $table->ulid('discount_id')->nullable();
            $table->unsignedInteger('charged_minutes')->nullable();
            $table->unsignedBigInteger('subtotal_amount')->nullable();
            $table->unsignedBigInteger('discount_amount')->nullable();
            $table->unsignedBigInteger('total_amount')->nullable();
            $table->json('pricing')->nullable();

            $table->foreign(['tenant_id', 'tariff_id'])->references(['tenant_id', 'id'])->on('tariffs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tariff_version_id'])->references(['tenant_id', 'id'])->on('tariff_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'discount_id'])->references(['tenant_id', 'id'])->on('discounts')->restrictOnDelete();
        });

        $this->restoreOpenSessionIndexes();

        // Stays closed before pricing existed carry no charge.
        DB::table('parking_sessions')->where('status', 'closed')->update(['charge_status' => 'unpriced']);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE tariffs ADD CONSTRAINT tariffs_status_check CHECK (status IN ('active', 'inactive'));
                ALTER TABLE tariffs ADD CONSTRAINT tariffs_currency_check CHECK (currency ~ '^[A-Z]{3}$');

                ALTER TABLE tariff_versions ADD CONSTRAINT tariff_versions_status_check CHECK (status IN ('active', 'inactive'));
                ALTER TABLE tariff_versions ADD CONSTRAINT tariff_versions_rates_check CHECK (
                    step_minutes > 0
                    AND (daily_max_amount IS NULL OR daily_max_amount > 0)
                    AND priority BETWEEN -100 AND 100
                    AND (grace_minutes IS NULL OR grace_minutes <= 240)
                );

                ALTER TABLE discounts ADD CONSTRAINT discounts_type_check CHECK (
                    (type = 'percentage' AND value BETWEEN 1 AND 10000)
                    OR (type = 'fixed_amount' AND value > 0 AND currency IS NOT NULL)
                    OR (type = 'free_minutes' AND value > 0)
                );
                ALTER TABLE discounts ADD CONSTRAINT discounts_application_check CHECK (application IN ('manual', 'automatic'));
                ALTER TABLE discounts ADD CONSTRAINT discounts_status_check CHECK (status IN ('active', 'inactive'));
                ALTER TABLE discounts ADD CONSTRAINT discounts_validity_check CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_from <= valid_until);

                ALTER TABLE parking_sessions ADD CONSTRAINT parking_sessions_charge_check CHECK (
                    (status IN ('open', 'cancelled') AND charge_status IS NULL AND total_amount IS NULL AND tariff_version_id IS NULL)
                    OR (status = 'closed' AND charge_status = 'unpriced' AND total_amount IS NULL AND tariff_version_id IS NULL)
                    OR (
                        status = 'closed' AND charge_status = 'priced'
                        AND currency IS NOT NULL AND tariff_version_id IS NOT NULL AND charged_minutes IS NOT NULL
                        AND subtotal_amount IS NOT NULL AND discount_amount IS NOT NULL
                        AND total_amount = subtotal_amount - discount_amount
                    )
                );
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE parking_sessions DROP CONSTRAINT IF EXISTS parking_sessions_charge_check');
        }

        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'tariff_id']);
            $table->dropForeign(['tenant_id', 'tariff_version_id']);
            $table->dropForeign(['tenant_id', 'discount_id']);
            $table->dropColumn([
                'charge_status',
                'currency',
                'tariff_id',
                'tariff_version_id',
                'discount_id',
                'charged_minutes',
                'subtotal_amount',
                'discount_amount',
                'total_amount',
                'pricing',
            ]);
        });

        $this->restoreOpenSessionIndexes();

        Schema::dropIfExists('discounts');
        Schema::dropIfExists('tariff_versions');
        Schema::dropIfExists('tariffs');

        Schema::table('parking_lots', function (Blueprint $table) {
            $table->dropUnique(['id', 'currency']);
        });
    }

    /**
     * SQLite alters a table by rebuilding it and recreates partial indexes
     * without their WHERE clause, which would allow one session per space
     * forever. PostgreSQL alters in place and keeps them.
     */
    private function restoreOpenSessionIndexes(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS parking_sessions_one_open_per_vehicle');
        DB::statement('DROP INDEX IF EXISTS parking_sessions_one_open_per_space');
        DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_vehicle ON parking_sessions (parking_lot_id, vehicle_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_space ON parking_sessions (space_id) WHERE status = 'open' AND space_id IS NOT NULL");
    }
};
