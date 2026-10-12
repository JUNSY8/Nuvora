# ADR-012: Contexto de tenant y RBAC

La versión 0.2 resuelve el tenant desde la membresía activa del usuario autenticado. El encabezado `X-Tenant-Id` y la sesión solo eligen entre membresías ya concedidas. Un `tenant_id` enviado en el cuerpo no otorga acceso.

La autorización consulta permisos, no el nombre del rol. Cada empresa recibe roles de sistema con un catálogo fijo de permisos. Un rol personalizado solo puede conceder permisos de ese catálogo. Solo un propietario puede otorgar o retirar el rol de propietario, y siempre debe quedar al menos un propietario activo.

Las tablas de membresías, roles y auditoría activan Row Level Security en PostgreSQL. La política limita las filas cuando la conexión define `nuvora.tenant_id`. El usuario local de PostgreSQL es superusuario y omite esas políticas; el aislamiento que se prueba en la aplicación sigue siendo obligatorio. Las restricciones `CHECK` de estado se crean en PostgreSQL. SQLite, usado por las pruebas, no las recibe porque esta versión del constructor de esquema no las expresa de forma portable.
