<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A manual renewal opens a period that will be charged (0.9). The client
 * sends an Idempotency-Key per attempt so a retried or double-clicked
 * renewal returns the first result instead of opening a second period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_periods', function (Blueprint $table) {
            $table->string('idempotency_key', 80)->nullable();
            $table->unique(['membership_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('membership_periods', function (Blueprint $table) {
            $table->dropUnique(['membership_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
