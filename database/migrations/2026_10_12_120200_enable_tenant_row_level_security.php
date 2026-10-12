<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenant_memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE roles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE role_permissions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE membership_roles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;

            CREATE POLICY tenant_memberships_tenant_context ON tenant_memberships
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

            CREATE POLICY roles_tenant_context ON roles
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

            CREATE POLICY role_permissions_tenant_context ON role_permissions
                USING (
                    current_setting('nuvora.tenant_id', true) IS NULL
                    OR btrim(current_setting('nuvora.tenant_id', true)) = ''
                    OR EXISTS (
                        SELECT 1 FROM roles
                        WHERE roles.id = role_permissions.role_id
                          AND roles.tenant_id::text = current_setting('nuvora.tenant_id', true)
                    )
                )
                WITH CHECK (
                    current_setting('nuvora.tenant_id', true) IS NULL
                    OR btrim(current_setting('nuvora.tenant_id', true)) = ''
                    OR EXISTS (
                        SELECT 1 FROM roles
                        WHERE roles.id = role_permissions.role_id
                          AND roles.tenant_id::text = current_setting('nuvora.tenant_id', true)
                    )
                );

            CREATE POLICY membership_roles_tenant_context ON membership_roles
                USING (
                    current_setting('nuvora.tenant_id', true) IS NULL
                    OR btrim(current_setting('nuvora.tenant_id', true)) = ''
                    OR EXISTS (
                        SELECT 1 FROM tenant_memberships
                        WHERE tenant_memberships.id = membership_roles.membership_id
                          AND tenant_memberships.tenant_id::text = current_setting('nuvora.tenant_id', true)
                    )
                )
                WITH CHECK (
                    current_setting('nuvora.tenant_id', true) IS NULL
                    OR btrim(current_setting('nuvora.tenant_id', true)) = ''
                    OR EXISTS (
                        SELECT 1 FROM tenant_memberships
                        WHERE tenant_memberships.id = membership_roles.membership_id
                          AND tenant_memberships.tenant_id::text = current_setting('nuvora.tenant_id', true)
                    )
                );

            CREATE POLICY audit_logs_tenant_context ON audit_logs
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

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS audit_logs_tenant_context ON audit_logs;
            DROP POLICY IF EXISTS membership_roles_tenant_context ON membership_roles;
            DROP POLICY IF EXISTS role_permissions_tenant_context ON role_permissions;
            DROP POLICY IF EXISTS roles_tenant_context ON roles;
            DROP POLICY IF EXISTS tenant_memberships_tenant_context ON tenant_memberships;

            ALTER TABLE audit_logs DISABLE ROW LEVEL SECURITY;
            ALTER TABLE membership_roles DISABLE ROW LEVEL SECURITY;
            ALTER TABLE role_permissions DISABLE ROW LEVEL SECURITY;
            ALTER TABLE roles DISABLE ROW LEVEL SECURITY;
            ALTER TABLE tenant_memberships DISABLE ROW LEVEL SECURITY;
        SQL);
    }
};
