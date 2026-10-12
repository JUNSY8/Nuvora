# Módulos

| Módulo | Responsabilidad | Estado en 0.2 |
| --- | --- | --- |
| Identity | Usuarios y autenticación | Sesión, registro, verificación de correo y restablecimiento de contraseña |
| Tenancy | Empresas, membresías, contexto y RBAC | Activo |
| Parking | Sedes, zonas y espacios | Pendiente |
| Vehicles | Vehículos | Pendiente |
| Customers | Clientes | Pendiente |
| Reservations | Reservas y capacidad | Pendiente |
| ParkingSessions | Entrada y salida | Pendiente |
| Pricing | Tarifas | Pendiente |
| Memberships | Membresías | Pendiente |
| Payments | Pagos | Pendiente |
| Billing | Facturación | Pendiente |
| Notifications | Notificaciones | Pendiente |
| Reporting | Reportes | Pendiente |
| Analytics | Analítica | Pendiente |
| Devices | Dispositivos | Pendiente |
| AccessControl | Control de acceso | Pendiente |
| LPR | Lectura de placas | Pendiente |
| Audit | Auditoría | Registro de acciones sensibles y consulta por tenant |
| Platform | Administración de la plataforma | Pendiente |
| Shared | Correlación, errores, salud y logging | Activo |

El tenant activo sale de la membresía autenticada, no del identificador enviado por el cliente. La decisión está en ADR-002 y ADR-012.
