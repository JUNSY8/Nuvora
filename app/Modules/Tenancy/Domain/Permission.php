<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

final class Permission
{
    public const TENANTS_VIEW = 'tenants.view';

    public const TENANTS_UPDATE = 'tenants.update';

    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const PARKING_VIEW = 'parking.view';

    public const PARKING_CREATE = 'parking.create';

    public const PARKING_UPDATE = 'parking.update';

    public const PARKING_DELETE = 'parking.delete';

    public const DEVICES_VIEW = 'devices.view';

    public const DEVICES_MANAGE = 'devices.manage';

    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_CREATE = 'customers.create';

    public const CUSTOMERS_UPDATE = 'customers.update';

    public const VEHICLES_VIEW = 'vehicles.view';

    public const VEHICLES_CREATE = 'vehicles.create';

    public const VEHICLES_UPDATE = 'vehicles.update';

    public const SESSIONS_VIEW = 'sessions.view';

    public const SESSIONS_CREATE = 'sessions.create';

    public const SESSIONS_CLOSE = 'sessions.close';

    public const SESSIONS_CANCEL = 'sessions.cancel';

    public const SESSIONS_DISCOUNT = 'sessions.discount';

    public const TARIFFS_VIEW = 'tariffs.view';

    public const TARIFFS_MANAGE = 'tariffs.manage';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_CREATE = 'payments.create';

    public const PAYMENTS_REFUND = 'payments.refund';

    public const REPORTS_VIEW = 'reports.view';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TENANTS_VIEW,
            self::TENANTS_UPDATE,
            self::USERS_VIEW,
            self::USERS_MANAGE,
            self::ROLES_VIEW,
            self::ROLES_MANAGE,
            self::AUDIT_VIEW,
            self::PARKING_VIEW,
            self::PARKING_CREATE,
            self::PARKING_UPDATE,
            self::PARKING_DELETE,
            self::DEVICES_VIEW,
            self::DEVICES_MANAGE,
            self::CUSTOMERS_VIEW,
            self::CUSTOMERS_CREATE,
            self::CUSTOMERS_UPDATE,
            self::VEHICLES_VIEW,
            self::VEHICLES_CREATE,
            self::VEHICLES_UPDATE,
            self::SESSIONS_VIEW,
            self::SESSIONS_CREATE,
            self::SESSIONS_CLOSE,
            self::SESSIONS_CANCEL,
            self::SESSIONS_DISCOUNT,
            self::TARIFFS_VIEW,
            self::TARIFFS_MANAGE,
            self::PAYMENTS_VIEW,
            self::PAYMENTS_CREATE,
            self::PAYMENTS_REFUND,
            self::REPORTS_VIEW,
        ];
    }
}
