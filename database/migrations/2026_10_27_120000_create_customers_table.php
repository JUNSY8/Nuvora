<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('type', 20);
            $table->string('name', 120);
            $table->string('document_type', 20)->nullable();
            $table->string('document_number', 40)->nullable();
            // Uppercase letters and digits only, so "1.020-304" and "1020304" collide.
            $table->string('document_normalized', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            // Individuals can be affiliated to a business or corporate customer.
            $table->ulid('business_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'business_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->unique(['tenant_id', 'document_type', 'document_normalized']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'business_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE customers ADD CONSTRAINT customers_type_check CHECK (type IN ('individual', 'business', 'corporate'));
                ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active', 'inactive'));
                ALTER TABLE customers ADD CONSTRAINT customers_document_pair_check CHECK ((document_type IS NULL) = (document_number IS NULL) AND (document_number IS NULL) = (document_normalized IS NULL));
                ALTER TABLE customers ADD CONSTRAINT customers_not_own_business_check CHECK (business_id IS NULL OR business_id <> id);
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
