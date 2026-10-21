<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_lots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->string('description', 500)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('city', 80)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('grace_period_minutes')->default(15);
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            // Target of composite foreign keys: children must share the lot's tenant.
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('parking_lot_operating_hours', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->unsignedTinyInteger('day_of_week');
            $table->string('mode', 20);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->cascadeOnDelete();
            $table->unique(['parking_lot_id', 'day_of_week']);
        });

        Schema::create('parking_zones', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->string('name', 80);
            $table->string('code', 40);
            $table->string('description', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->cascadeOnDelete();
            $table->unique(['parking_lot_id', 'code']);
            $table->unique(['parking_lot_id', 'id']);
        });

        Schema::create('parking_spaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->ulid('zone_id')->nullable();
            $table->string('code', 40);
            $table->string('type', 20);
            $table->string('status', 20);
            $table->string('floor', 20)->nullable();
            $table->boolean('is_reservable')->default(false);
            $table->boolean('is_assignable')->default(false);
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            // A space can only point to a zone of its own lot.
            $table->foreign(['parking_lot_id', 'zone_id'])->references(['parking_lot_id', 'id'])->on('parking_zones')->restrictOnDelete();
            $table->unique(['parking_lot_id', 'code']);
            $table->index(['parking_lot_id', 'status']);
            $table->index(['parking_lot_id', 'zone_id']);
        });

        Schema::create('parking_gates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->string('name', 80);
            $table->string('code', 40);
            $table->string('direction', 20);
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            $table->unique(['parking_lot_id', 'code']);
            $table->unique(['parking_lot_id', 'id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE parking_lots ADD CONSTRAINT parking_lots_status_check CHECK (status IN ('active', 'inactive', 'maintenance'));
                ALTER TABLE parking_lots ADD CONSTRAINT parking_lots_currency_check CHECK (currency ~ '^[A-Z]{3}$');
                ALTER TABLE parking_lots ADD CONSTRAINT parking_lots_grace_check CHECK (grace_period_minutes <= 240);
                ALTER TABLE parking_lots ADD CONSTRAINT parking_lots_coordinates_check CHECK (
                    (latitude IS NULL OR latitude BETWEEN -90 AND 90)
                    AND (longitude IS NULL OR longitude BETWEEN -180 AND 180)
                );

                ALTER TABLE parking_lot_operating_hours ADD CONSTRAINT parking_hours_day_check CHECK (day_of_week BETWEEN 1 AND 7);
                ALTER TABLE parking_lot_operating_hours ADD CONSTRAINT parking_hours_mode_check CHECK (
                    (mode IN ('closed', 'open_24h') AND opens_at IS NULL AND closes_at IS NULL)
                    OR (mode = 'scheduled' AND opens_at IS NOT NULL AND closes_at IS NOT NULL AND opens_at <> closes_at)
                );

                ALTER TABLE parking_spaces ADD CONSTRAINT parking_spaces_type_check CHECK (type IN ('car', 'motorcycle', 'truck', 'van', 'ev', 'disabled', 'vip', 'reserved'));
                ALTER TABLE parking_spaces ADD CONSTRAINT parking_spaces_status_check CHECK (status IN ('available', 'occupied', 'reserved', 'blocked', 'maintenance', 'out_of_service'));

                ALTER TABLE parking_gates ADD CONSTRAINT parking_gates_direction_check CHECK (direction IN ('entry', 'exit', 'bidirectional'));
                ALTER TABLE parking_gates ADD CONSTRAINT parking_gates_status_check CHECK (status IN ('active', 'inactive', 'maintenance'));
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_gates');
        Schema::dropIfExists('parking_spaces');
        Schema::dropIfExists('parking_zones');
        Schema::dropIfExists('parking_lot_operating_hours');
        Schema::dropIfExists('parking_lots');
    }
};
