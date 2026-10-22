<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target of the session's composite reference: a session can only hold a space of its own lot.
        Schema::table('parking_spaces', function (Blueprint $table) {
            $table->unique(['parking_lot_id', 'id']);
        });

        Schema::create('parking_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->ulid('vehicle_id');
            $table->ulid('customer_id')->nullable();
            $table->ulid('space_id')->nullable();
            // Snapshot at entry, so history stays readable after plate corrections or space changes.
            $table->string('plate', 12);
            $table->char('country', 2);
            $table->string('vehicle_type', 20);
            $table->string('space_code', 40)->nullable();
            $table->string('status', 20);
            $table->timestamp('entry_at');
            $table->timestamp('exit_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('entry_source', 20);
            $table->string('exit_source', 20)->nullable();
            $table->ulid('entry_gate_id')->nullable();
            $table->ulid('exit_gate_id')->nullable();
            $table->ulid('opened_by')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->string('entry_idempotency_key', 80)->nullable();
            $table->string('exit_idempotency_key', 80)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['parking_lot_id', 'space_id'])->references(['parking_lot_id', 'id'])->on('parking_spaces')->restrictOnDelete();
            $table->foreign(['parking_lot_id', 'entry_gate_id'])->references(['parking_lot_id', 'id'])->on('parking_gates')->restrictOnDelete();
            $table->foreign(['parking_lot_id', 'exit_gate_id'])->references(['parking_lot_id', 'id'])->on('parking_gates')->restrictOnDelete();
            $table->foreign('opened_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'entry_idempotency_key']);
            $table->unique(['tenant_id', 'exit_idempotency_key']);
            $table->index(['tenant_id', 'parking_lot_id', 'status', 'entry_at']);
            $table->index(['tenant_id', 'parking_lot_id', 'exit_at']);
            $table->index(['tenant_id', 'plate']);
            $table->index(['tenant_id', 'vehicle_id', 'entry_at']);
        });

        // Invariants 1 and 2 of the README: one open session per vehicle and lot, one per space.
        DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_vehicle ON parking_sessions (parking_lot_id, vehicle_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX parking_sessions_one_open_per_space ON parking_sessions (space_id) WHERE status = 'open' AND space_id IS NOT NULL");

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE parking_sessions ADD CONSTRAINT parking_sessions_status_check CHECK (status IN ('open', 'closed', 'cancelled'));
                ALTER TABLE parking_sessions ADD CONSTRAINT parking_sessions_source_check CHECK (
                    entry_source IN ('manual') AND (exit_source IS NULL OR exit_source IN ('manual'))
                );
                ALTER TABLE parking_sessions ADD CONSTRAINT parking_sessions_lifecycle_check CHECK (
                    (status = 'open' AND exit_at IS NULL AND cancelled_at IS NULL AND duration_minutes IS NULL)
                    OR (status = 'closed' AND exit_at IS NOT NULL AND exit_at >= entry_at AND cancelled_at IS NULL AND duration_minutes IS NOT NULL)
                    OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND exit_at IS NULL AND cancel_reason IS NOT NULL)
                );
                ALTER TABLE parking_sessions ADD CONSTRAINT parking_sessions_plate_check CHECK (plate ~ '^[A-Z0-9]{2,12}$');
            SQL);
        }

        // Before 0.5 no session could occupy a space; occupied rows only come
        // from the demo seeder and would stay stuck without a session to end.
        DB::table('parking_spaces')->where('status', 'occupied')->update([
            'status' => 'available',
            'status_changed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_sessions');

        Schema::table('parking_spaces', function (Blueprint $table) {
            $table->dropUnique(['parking_lot_id', 'id']);
        });
    }
};
