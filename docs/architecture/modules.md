# Módulos

| Módulo | Responsabilidad | Estado en 0.6 |
| --- | --- | --- |
| Identity | Usuarios y autenticación | Sesión, registro, verificación de correo y restablecimiento de contraseña |
| Tenancy | Empresas, membresías, contexto y RBAC | Activo |
| Parking | Sedes, horario, configuración, zonas, espacios y puertas | Activo. Expone `ParkingInfrastructureDirectory` y `ParkingSpaceAllocator` a otros módulos. Un parqueadero con tarifas no cambia de moneda |
| Vehicles | Vehículos, placas, vínculos con clientes e historial | Activo. Normaliza placas con reglas por país (`PlateRules`). Expone `VehicleDirectory` a las sesiones |
| Customers | Personas, empresas y corporativos | Activo. Expone `CustomerDirectory` a otros módulos |
| Reservations | Reservas y capacidad | Pendiente |
| ParkingSessions | Entrada, salida, asignación de espacio y ciclo de la sesión | Activo. Operación manual; la salida calcula y guarda el cobro con Tariffs. El recaudo llega con Payments |
| Tariffs | Tarifas, descuentos, promociones y simulación | Activo. Tarifas versionadas por parqueadero. Expone `PricingEngine` a las sesiones |
| Memberships | Membresías | Pendiente |
| Payments | Pagos | Pendiente |
| Billing | Facturación | Pendiente |
| Notifications | Notificaciones | Pendiente |
| Reporting | Reportes | Pendiente |
| Analytics | Analítica | Pendiente |
| Devices | Registro de dispositivos | Registro y estado manual (mantenimiento). Los reportes de Edge llegan en 0.13 |
| AccessControl | Control de acceso | Pendiente |
| LPR | Lectura de placas | Pendiente |
| Audit | Auditoría | Registro de acciones sensibles y consulta por tenant |
| Platform | Administración de la plataforma | Pendiente |
| Shared | Correlación, errores, salud, logging y marcadores de cambios en vivo | Activo |

El tenant activo sale de la membresía autenticada, no del identificador enviado por el cliente. La decisión está en ADR-002 y ADR-012. La infraestructura de parqueaderos y el registro de dispositivos están en ADR-013; clientes, vehículos y placas en ADR-014.

Devices consulta Parking solo a través de `ParkingInfrastructureDirectory`; no lee sus modelos.

Vehicles consulta Customers solo a través de `CustomerDirectory`. Customers no depende de Vehicles: la ficha del cliente pide sus vehículos a la API de Vehicles.

ParkingSessions ocupa y libera espacios con `ParkingSpaceAllocator`, identifica o registra el vehículo con `VehicleDirectory` y resume clientes con `CustomerDirectory`, siempre dentro de su propia transacción. Ningún módulo depende de ParkingSessions. La decisión está en ADR-015.

Tariffs lee moneda, zona horaria, gracia y zonas por `ParkingInfrastructureDirectory`. ParkingSessions le pide el precio de una estancia a `PricingEngine` dentro de la transacción de la salida y guarda el resultado en la sesión. Tariffs no depende de ParkingSessions. La decisión está en ADR-016.
