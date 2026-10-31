<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            // Canonical plate: uppercase letters and digits, no separators.
            $table->string('plate', 12);
            $table->char('country', 2);
            $table->string('type', 20);
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('color', 40)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->boolean('is_electric')->default(false);
            $table->string('status', 20);
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'country', 'plate']);
            $table->index(['tenant_id', 'plate']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('customer_id');
            $table->ulid('vehicle_id');
            $table->string('relationship', 20);
            $table->timestamp('started_at');
            // Links are ended, never deleted, so the vehicle history stays explainable.
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->index(['tenant_id', 'customer_id', 'ended_at']);
            $table->index(['tenant_id', 'vehicle_id', 'ended_at']);
        });

        Schema::create('vehicle_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('vehicle_id');
            $table->string('type', 40);
            $table->ulid('actor_user_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'vehicle_id', 'occurred_at']);
        });

        // Partial unique indexes are supported by PostgreSQL and SQLite alike.
        DB::statement("CREATE UNIQUE INDEX customer_vehicles_one_active_owner ON customer_vehicles (vehicle_id) WHERE relationship = 'owner' AND ended_at IS NULL");
        DB::statement('CREATE UNIQUE INDEX customer_vehicles_one_active_link ON customer_vehicles (customer_id, vehicle_id) WHERE ended_at IS NULL');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check CHECK (type IN ('car', 'motorcycle', 'bicycle', 'van', 'truck', 'bus', 'other'));
                ALTER TABLE vehicles ADD CONSTRAINT vehicles_status_check CHECK (status IN ('active', 'inactive', 'blocked'));
                ALTER TABLE vehicles ADD CONSTRAINT vehicles_blocked_reason_check CHECK (status <> 'blocked' OR status_reason IS NOT NULL);
                ALTER TABLE vehicles ADD CONSTRAINT vehicles_plate_check CHECK (plate ~ '^[A-Z0-9]{2,12}$');
                ALTER TABLE vehicles ADD CONSTRAINT vehicles_country_check CHECK (country ~ '^[A-Z]{2}$');
                ALTER TABLE vehicles ADD CONSTRAINT vehicles_year_check CHECK (year IS NULL OR year BETWEEN 1900 AND 2100);
                ALTER TABLE customer_vehicles ADD CONSTRAINT customer_vehicles_relationship_check CHECK (relationship IN ('owner', 'driver', 'authorized'));
                ALTER TABLE customer_vehicles ADD CONSTRAINT customer_vehicles_period_check CHECK (ended_at IS NULL OR ended_at >= started_at);
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_events');
        Schema::dropIfExists('customer_vehicles');
        Schema::dropIfExists('vehicles');
    }
};
