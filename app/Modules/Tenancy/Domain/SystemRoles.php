<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

final class SystemRoles
{
    public const OWNER = 'tenant_owner';

    /**
     * @return array<string, array{name: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            self::OWNER => [
                'name' => 'Propietario',
                'permissions' => Permission::all(),
            ],
            'tenant_administrator' => [
                'name' => 'Administrador',
                'permissions' => [
                    Permission::TENANTS_VIEW,
                    Permission::TENANTS_UPDATE,
                    Permission::USERS_VIEW,
                    Permission::USERS_MANAGE,
                    Permission::ROLES_VIEW,
                    Permission::ROLES_MANAGE,
                    Permission::AUDIT_VIEW,
                    Permission::PARKING_VIEW,
                    Permission::PARKING_CREATE,
                    Permission::PARKING_UPDATE,
                    Permission::DEVICES_VIEW,
                    Permission::DEVICES_MANAGE,
                    Permission::CUSTOMERS_VIEW,
                    Permission::CUSTOMERS_CREATE,
                    Permission::CUSTOMERS_UPDATE,
                    Permission::VEHICLES_VIEW,
                    Permission::VEHICLES_CREATE,
                    Permission::VEHICLES_UPDATE,
                    Permission::SESSIONS_VIEW,
                    Permission::SESSIONS_CREATE,
                    Permission::SESSIONS_CLOSE,
                    Permission::SESSIONS_CANCEL,
                    Permission::SESSIONS_DISCOUNT,
                    Permission::TARIFFS_VIEW,
                    Permission::TARIFFS_MANAGE,
                    Permission::RESERVATIONS_VIEW,
                    Permission::RESERVATIONS_MANAGE,
                    Permission::MEMBERSHIPS_VIEW,
                    Permission::MEMBERSHIPS_MANAGE,
                    Permission::PAYMENTS_VIEW,
                    Permission::PAYMENTS_CREATE,
                    Permission::PAYMENTS_RECONCILE,
                    Permission::PAYMENTS_CONFIGURE,
                    Permission::REPORTS_VIEW,
                ],
            ],
            'supervisor' => [
                'name' => 'Supervisor',
                'permissions' => [
                    Permission::TENANTS_VIEW,
                    Permission::USERS_VIEW,
                    Permission::AUDIT_VIEW,
                    Permission::PARKING_VIEW,
                    Permission::DEVICES_VIEW,
                    Permission::CUSTOMERS_VIEW,
                    Permission::VEHICLES_VIEW,
                    Permission::SESSIONS_VIEW,
                    Permission::SESSIONS_CREATE,
                    Permission::SESSIONS_CLOSE,
                    Permission::SESSIONS_CANCEL,
                    Permission::SESSIONS_DISCOUNT,
                    Permission::TARIFFS_VIEW,
                    Permission::RESERVATIONS_VIEW,
                    Permission::RESERVATIONS_MANAGE,
                    Permission::MEMBERSHIPS_VIEW,
                    Permission::MEMBERSHIPS_MANAGE,
                    Permission::PAYMENTS_VIEW,
                    Permission::PAYMENTS_RECONCILE,
                    Permission::REPORTS_VIEW,
                ],
            ],
            'cashier' => [
                'name' => 'Cajero',
                'permissions' => [
                    Permission::PARKING_VIEW,
                    Permission::CUSTOMERS_VIEW,
                    Permission::VEHICLES_VIEW,
                    Permission::SESSIONS_VIEW,
                    Permission::SESSIONS_CREATE,
                    Permission::SESSIONS_CLOSE,
                    Permission::SESSIONS_DISCOUNT,
                    Permission::TARIFFS_VIEW,
                    Permission::RESERVATIONS_VIEW,
                    Permission::RESERVATIONS_MANAGE,
                    Permission::MEMBERSHIPS_VIEW,
                    Permission::MEMBERSHIPS_MANAGE,
                    Permission::PAYMENTS_VIEW,
                    Permission::PAYMENTS_CREATE,
                ],
            ],
            'parking_operator' => [
                'name' => 'Operador',
                'permissions' => [
                    Permission::PARKING_VIEW,
                    Permission::VEHICLES_VIEW,
                    Permission::SESSIONS_VIEW,
                    Permission::SESSIONS_CREATE,
                    Permission::SESSIONS_CLOSE,
                    Permission::TARIFFS_VIEW,
                    Permission::RESERVATIONS_VIEW,
                    Permission::MEMBERSHIPS_VIEW,
                ],
            ],
            'accountant' => [
                'name' => 'Contador',
                'permissions' => [
                    Permission::TARIFFS_VIEW,
                    Permission::MEMBERSHIPS_VIEW,
                    Permission::PAYMENTS_VIEW,
                    Permission::REPORTS_VIEW,
                ],
            ],
            'report_viewer' => [
                'name' => 'Consulta',
                'permissions' => [
                    Permission::PARKING_VIEW,
                    Permission::SESSIONS_VIEW,
                    Permission::TARIFFS_VIEW,
                    Permission::RESERVATIONS_VIEW,
                    Permission::MEMBERSHIPS_VIEW,
                    Permission::REPORTS_VIEW,
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }
}
