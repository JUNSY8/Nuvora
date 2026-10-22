<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('buffer_minutes');
            $table->unsignedSmallInteger('hold_minutes');
            $table->unsignedSmallInteger('arrival_tolerance_minutes');
            $table->unsignedSmallInteger('min_duration_minutes');
            $table->unsignedInteger('max_duration_minutes');
            $table->unsignedSmallInteger('max_advance_days');
            $table->boolean('within_operating_hours')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->cascadeOnDelete();
            $table->unique('parking_lot_id');
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->string('code', 12);
            $table->string('status', 20);
            $table->ulid('vehicle_id');
            $table->ulid('customer_id')->nullable();
            // Snapshot, so the list stays readable after plate corrections or space changes.
            $table->string('plate', 12);
            $table->char('country', 2);
            $table->string('vehicle_type', 20);
            $table->ulid('zone_id')->nullable();
            $table->ulid('space_id')->nullable();
            $table->string('space_code', 40)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // Derived from the lot's policy when the reservation was made.
            $table->timestamp('blocked_from');
            $table->timestamp('blocked_until');
            $table->timestamp('arrival_from');
            $table->timestamp('no_show_after');
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('quoted_amount')->nullable();
            $table->ulid('tariff_version_id')->nullable();
            $table->json('pricing')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 80)->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('cancelled_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['parking_lot_id', 'zone_id'])->references(['parking_lot_id', 'id'])->on('parking_zones')->restrictOnDelete();
            $table->foreign(['parking_lot_id', 'space_id'])->references(['parking_lot_id', 'id'])->on('parking_spaces')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tariff_version_id'])->references(['tenant_id', 'id'])->on('tariff_versions')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'parking_lot_id', 'status', 'starts_at']);
            $table->index(['parking_lot_id', 'status', 'blocked_from']);
            $table->index(['tenant_id', 'vehicle_id', 'starts_at']);
            $table->index(['tenant_id', 'plate']);
        });

        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->ulid('reservation_id')->nullable();
            $table->foreign(['tenant_id', 'reservation_id'])->references(['tenant_id', 'id'])->on('reservations')->restrictOnDelete();
        });

        $this->restoreSessionIndexes();

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE EXTENSION IF NOT EXISTS btree_gist;

                ALTER TABLE reservation_policies ADD CONSTRAINT reservation_policies_values_check CHECK (
                    buffer_minutes <= 240 AND hold_minutes BETWEEN 1 AND 1440 AND arrival_tolerance_minutes <= 720
                    AND min_duration_minutes >= 15 AND max_duration_minutes >= min_duration_minutes AND max_advance_days BETWEEN 1 AND 365
                );

                ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (
                    status IN ('pending', 'confirmed', 'active', 'completed', 'cancelled', 'expired', 'no_show')
                );
                ALTER TABLE reservations ADD CONSTRAINT reservations_window_check CHECK (
                    ends_at > starts_at AND blocked_from <= starts_at AND blocked_until >= ends_at AND arrival_from <= starts_at AND no_show_after >= starts_at
                );
                ALTER TABLE reservations ADD CONSTRAINT reservations_lifecycle_check CHECK (
                    (status <> 'pending' OR hold_expires_at IS NOT NULL)
                    AND (status NOT IN ('confirmed', 'active', 'completed', 'no_show') OR confirmed_at IS NOT NULL)
                    AND (status NOT IN ('active', 'completed') OR checked_in_at IS NOT NULL)
                    AND (status <> 'completed' OR completed_at IS NOT NULL)
                    AND (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL))
                    AND (status NOT IN ('expired', 'no_show') OR expired_at IS NOT NULL)
                );
                ALTER TABLE reservations ADD CONSTRAINT reservations_plate_check CHECK (plate ~ '^[A-Z0-9]{2,12}$');

                -- Conflict prevention decided by the database: a space is never promised
                -- twice for overlapping windows (buffers included), and a vehicle does not
                -- hold two overlapping reservations in the same lot.
                ALTER TABLE reservations ADD CONSTRAINT reservations_space_no_overlap EXCLUDE USING gist (
                    space_id WITH =,
                    tsrange(blocked_from, blocked_until) WITH &&
                ) WHERE (space_id IS NOT NULL AND status IN ('pending', 'confirmed', 'active'));
                ALTER TABLE reservations ADD CONSTRAINT reservations_vehicle_no_overlap EXCLUDE USING gist (
                    parking_lot_id WITH =,
                    vehicle_id WITH =,
                    tsrange(starts_at, ends_at) WITH &&
                ) WHERE (status IN ('pending', 'confirmed', 'active'));
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'reservation_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS parking_sessions_one_per_reservation');
        }

        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropColumn('reservation_id');
        });

        $this->restoreSessionIndexes(withReservation: false);

        Schema::dropIfExists('reservations');
        Schema::dropIfExists('reservation_policies');
    }

    /**
     * SQLite alters a table by rebuilding it and recreates partial indexes
     * without their WHERE clause (see the 0.6 migration). A reservation is
     * used by at most one stay that was not cancelled.
     */
    private function restoreSessionIndexes(bool $withReservation = true): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS parking_sessions_one_open_per_vehicle');
            DB::statement('DROP INDEX IF EXISTS parking_sessions_one_open_per_space');
            DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_vehicle ON parking_sessions (parking_lot_id, vehicle_id) WHERE status = 'open'");
            DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_space ON parking_sessions (space_id) WHERE status = 'open' AND space_id IS NOT NULL");
        }

        if ($withReservation) {
            DB::statement('DROP INDEX IF EXISTS parking_sessions_one_per_reservation');
            DB::statement("CREATE UNIQUE INDEX parking_sessions_one_per_reservation ON parking_sessions (reservation_id) WHERE reservation_id IS NOT NULL AND status <> 'cancelled'");
        }
    }
};
