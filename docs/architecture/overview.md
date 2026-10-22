# Arquitectura

Nuvora Cloud es un monolito modular sobre Laravel. El navegador habla con una aplicación Vue independiente que consume `/api/v1`.

PostgreSQL es el sistema de registro. Redis cubre caché, sesiones, colas y límites de tasa. Los timestamps se persisten en UTC.

Los módulos viven en `app/Modules` y no acceden a los internos de otros módulos. La versión 0.2 dejó operativos Identidad, Tenancy y la base de Auditoría, además de los componentes compartidos de salud, correlación y errores. La versión 0.3 agrega Parking (sedes, horario, zonas, espacios y puertas) y el registro de Devices. La versión 0.4 agrega Customers y Vehicles, con normalización de placas por país, vínculos cliente-vehículo e historial del vehículo. La versión 0.5 agrega ParkingSessions: entrada y salida manual, asignación de espacios, idempotencia y actualización en vivo por consulta periódica de marcadores de cambio (ADR-015). La versión 0.6 agrega Tariffs: tarifas versionadas, descuentos, promociones y simulador; la salida calcula y guarda el cobro en unidades menores de la moneda (ADR-016). La versión 0.7 agrega Reservations: reservas de espacio, zona o parqueadero con prevención de conflictos en la base de datos, vencimiento y no-show por un barrido programado cada minuto (ADR-017). El resto de los módulos existe como límite, sin reglas de negocio todavía.

Las decisiones están en `docs/adr`.

La interfaz usa el sistema visual Velzon oscuro (tokens en `frontend/src/app/styles.css`, componentes en `frontend/src/components/ui`) y los íconos de Remix Icon, el set que usa Velzon, instalado como dependencia npm. No se copian archivos del tema.
