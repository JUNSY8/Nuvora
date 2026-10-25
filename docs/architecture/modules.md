# Módulos

| Módulo | Responsabilidad | Estado en 0.10 |
| --- | --- | --- |
| Identity | Usuarios y autenticación | Sesión, registro, verificación de correo y restablecimiento de contraseña |
| Tenancy | Empresas, membresías, contexto y RBAC | Activo |
| Parking | Sedes, horario, configuración, zonas, espacios y puertas | Activo. Expone `ParkingInfrastructureDirectory` y `ParkingSpaceAllocator` a otros módulos. Un parqueadero con tarifas no cambia de moneda. Espacios reservables y asignables a mensualidades |
| Vehicles | Vehículos, placas, vínculos con clientes e historial | Activo. Normaliza placas con reglas por país (`PlateRules`). Expone `VehicleDirectory` a las sesiones |
| Customers | Personas, empresas y corporativos | Activo. Expone `CustomerDirectory` a otros módulos |
| Reservations | Reservas, disponibilidad y ciclo de vida | Activo. Reservas de espacio, zona o parqueadero. Expone `ReservationDirectory` a las sesiones |
| ParkingSessions | Entrada, salida, asignación de espacio y ciclo de la sesión | Activo. Operación manual; la salida calcula y guarda el cobro con Tariffs, o lo marca cubierto por una mensualidad. Puede cobrar el pago en la misma salida y fijar el precio cotizado unos minutos. Implementa `SessionPayables` para Payments |
| Tariffs | Tarifas, descuentos, promociones y simulación | Activo. Tarifas versionadas por parqueadero. Expone `PricingEngine` a las sesiones |
| Memberships | Planes, mensualidades, periodos y acceso de clientes recurrentes | Activo. Venta, renovación (manual y automática), suspensión y vencimiento. Expone `MembershipDirectory` a las sesiones e implementa `PeriodPayables` para Payments |
| Payments | Pagos, reembolsos, recibos, cierre de caja y pasarelas | Activo. Efectivo y pagos manuales detrás de `PaymentGateway`; Wompi y PayU detrás de `OnlinePaymentProvider` (enlaces, webhooks, sincronización, conciliación). Cobra lo que los módulos exponen con `PayableSource` y publica saldos con `PaymentDirectory` |
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

Reservations usa Parking (bolsa de espacios reservables, horario, retención de espacios), Vehicles, Customers y el `PricingEngine` de Tariffs. ParkingSessions llama a `ReservationDirectory` en la entrada, la salida y la anulación. `SpaceCompatibility` vive en Parking para que sesiones y reservas la compartan sin depender una de la otra. La decisión está en ADR-017.

Memberships usa Parking (parqueaderos, zonas y espacios asignables), Vehicles, Customers y Tenancy (`TenantDirectory`, zona horaria de cada empresa para el scheduler). ParkingSessions llama a `MembershipDirectory` en la entrada (¿la mensualidad cubre esta llegada?), al calcular el precio (estancia cubierta) y para resumir la mensualidad de una sesión. Memberships no depende de ParkingSessions; de Tariffs solo reutiliza el valor `TimeWindow` (franjas semanales). La decisión está en ADR-018.

Payments no conoce las tablas de los módulos que cobran. ParkingSessions (`SessionPayables`) y Memberships (`PeriodPayables`) implementan su contrato `PayableSource` y se registran en `AppServiceProvider`. Payments bloquea el cobro por ese contrato mientras registra el pago. Los dos módulos leen los saldos con `PaymentDirectory`, y ParkingSessions llama a `CollectPayment` dentro de la transacción de la salida. Payments no depende de ninguno de ellos. La decisión está en ADR-019.

Los adaptadores de pasarela viven en `Payments/Infrastructure/Providers` y solo los conoce `OnlineProviders`. Las notificaciones llegan por `api/v1/webhooks/payments/{provider}/{account}` en el grupo `api`, sin sesión, y se verifican con la firma de la cuenta. La decisión está en ADR-020.
