<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'receipt_sequences',
        'payment_reconciliations',
        'payments',
        'payment_refunds',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
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

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse($this->tables) as $table) {
            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$table}_tenant_context ON {$table};
                ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;
            SQL);
        }
    }
};
