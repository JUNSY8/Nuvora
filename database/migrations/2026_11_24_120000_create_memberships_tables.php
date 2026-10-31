<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('category', 20);
            $table->string('billing_period', 20);
            $table->unsignedBigInteger('price_amount');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->unsignedSmallInteger('max_vehicles')->default(1);
            $table->unsignedInteger('capacity')->nullable();
            // Access rules; empty lists mean no restriction. Ids are checked on write.
            $table->json('parking_lot_ids');
            $table->json('zone_ids');
            $table->json('vehicle_types');
            $table->json('windows');
            $table->string('status', 20);
            $table->string('description', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('plan_id');
            $table->ulid('customer_id');
            $table->string('code', 12);
            $table->string('status', 20);
            // Snapshot of the plan's commercial terms when it was sold.
            $table->string('billing_period', 20);
            $table->unsignedBigInteger('price_amount');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('grace_days');
            $table->date('starts_on');
            // Day the current cycle of periods counts from; renewing a long-expired membership restarts it.
            $table->date('anchor_on');
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->unsignedInteger('periods_count')->default(1);
            $table->boolean('auto_renew')->default(true);
            $table->ulid('assigned_lot_id')->nullable();
            $table->ulid('assigned_space_id')->nullable();
            $table->string('assigned_space_code', 40)->nullable();
            $table->string('status_reason', 255)->nullable();
            $table->date('ended_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'plan_id'])->references(['tenant_id', 'id'])->on('membership_plans')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'assigned_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->foreign(['assigned_lot_id', 'assigned_space_id'])->references(['parking_lot_id', 'id'])->on('parking_spaces')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'current_period_end']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'plan_id', 'status']);
        });

        Schema::create('membership_vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('membership_id');
            $table->ulid('vehicle_id');
            $table->string('plate', 12);
            $table->timestamp('added_at');
            $table->timestamp('removed_at')->nullable();
            $table->ulid('added_by')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'vehicle_id']);
        });

        Schema::create('membership_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('membership_id');
            $table->unsignedInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            // Collecting it arrives with payments (0.9).
            $table->string('status', 20);
            $table->string('origin', 20);
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['membership_id', 'sequence']);
        });

        // One vehicle in a membership at a time; one holder per assigned space.
        DB::statement('CREATE UNIQUE INDEX membership_vehicles_one_active ON membership_vehicles (membership_id, vehicle_id) WHERE removed_at IS NULL');
        DB::statement("CREATE UNIQUE INDEX memberships_one_holder_per_space ON memberships (assigned_space_id) WHERE assigned_space_id IS NOT NULL AND status IN ('active', 'suspended')");

        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->ulid('membership_id')->nullable();
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        $this->restoreSessionIndexes();

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE membership_plans ADD CONSTRAINT membership_plans_values_check CHECK (
                    category IN ('monthly', 'weekly', 'corporate', 'vip', 'employee', 'resident', 'student', 'other')
                    AND billing_period IN ('weekly', 'monthly', 'quarterly', 'yearly')
                    AND status IN ('active', 'inactive')
                    AND max_vehicles BETWEEN 1 AND 500
                    AND grace_days <= 31
                    AND (capacity IS NULL OR capacity > 0)
                    AND currency ~ '^[A-Z]{3}$'
                );

                ALTER TABLE memberships ADD CONSTRAINT memberships_values_check CHECK (
                    status IN ('active', 'suspended', 'expired', 'cancelled')
                    AND billing_period IN ('weekly', 'monthly', 'quarterly', 'yearly')
                    AND current_period_end >= current_period_start
                    AND current_period_start >= starts_on
                    AND (status <> 'cancelled' OR (ended_on IS NOT NULL AND status_reason IS NOT NULL))
                    AND (status <> 'suspended' OR status_reason IS NOT NULL)
                    AND ((assigned_lot_id IS NULL) = (assigned_space_id IS NULL))
                );

                ALTER TABLE membership_periods ADD CONSTRAINT membership_periods_values_check CHECK (
                    ends_on >= starts_on AND status IN ('due', 'paid', 'void') AND origin IN ('subscription', 'renewal', 'automatic')
                );

                ALTER TABLE parking_sessions DROP CONSTRAINT IF EXISTS parking_sessions_charge_check;
                ALTER TABLE parking_sessions ADD CONSTRAINT parking_sessions_charge_check CHECK (
                    (status IN ('open', 'cancelled') AND charge_status IS NULL AND total_amount IS NULL AND tariff_version_id IS NULL)
                    OR (status = 'closed' AND charge_status = 'unpriced' AND total_amount IS NULL AND tariff_version_id IS NULL)
                    OR (
                        status = 'closed' AND charge_status = 'priced'
                        AND currency IS NOT NULL AND tariff_version_id IS NOT NULL AND charged_minutes IS NOT NULL
                        AND subtotal_amount IS NOT NULL AND discount_amount IS NOT NULL
                        AND total_amount = subtotal_amount - discount_amount
                    )
                    OR (
                        status = 'closed' AND charge_status = 'covered' AND membership_id IS NOT NULL
                        AND currency IS NOT NULL AND total_amount = 0 AND subtotal_amount = discount_amount
                    )
                );
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("UPDATE parking_sessions SET charge_status = 'unpriced', total_amount = NULL, subtotal_amount = NULL, discount_amount = NULL, tariff_version_id = NULL, tariff_id = NULL WHERE charge_status = 'covered'");
        }

        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'membership_id']);
        });

        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropColumn('membership_id');
        });

        $this->restoreSessionIndexes();

        Schema::dropIfExists('membership_periods');
        Schema::dropIfExists('membership_vehicles');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
    }

    /**
     * SQLite rebuilds the table to alter it and loses the WHERE of partial
     * indexes (see the 0.6 and 0.7 migrations). PostgreSQL keeps them.
     */
    private function restoreSessionIndexes(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS parking_sessions_one_open_per_vehicle');
        DB::statement('DROP INDEX IF EXISTS parking_sessions_one_open_per_space');
        DB::statement('DROP INDEX IF EXISTS parking_sessions_one_per_reservation');
        DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_vehicle ON parking_sessions (parking_lot_id, vehicle_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_space ON parking_sessions (space_id) WHERE status = 'open' AND space_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX parking_sessions_one_per_reservation ON parking_sessions (reservation_id) WHERE reservation_id IS NOT NULL AND status <> 'cancelled'");
    }
};
