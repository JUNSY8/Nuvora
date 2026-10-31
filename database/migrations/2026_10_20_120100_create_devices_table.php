<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('parking_lot_id');
            $table->ulid('gate_id')->nullable();
            $table->string('name', 80);
            $table->string('code', 40);
            $table->string('type', 30);
            $table->string('vendor', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('firmware_version', 40)->nullable();
            $table->string('status', 20);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'parking_lot_id'])->references(['tenant_id', 'id'])->on('parking_lots')->restrictOnDelete();
            // A device can only be mounted on a gate of its own lot.
            $table->foreign(['parking_lot_id', 'gate_id'])->references(['parking_lot_id', 'id'])->on('parking_gates')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'parking_lot_id']);
            $table->index(['tenant_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE devices ADD CONSTRAINT devices_type_check CHECK (type IN ('camera', 'barrier', 'ticket_printer', 'display', 'sensor', 'access_reader', 'payment_terminal', 'kiosk'));
                ALTER TABLE devices ADD CONSTRAINT devices_status_check CHECK (status IN ('online', 'offline', 'degraded', 'unknown', 'maintenance'));
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
