# ADR-017: Reservas

La versión 0.7 agrega el módulo Reservations: reservas de un espacio, de una zona o de cualquier espacio del parqueadero, con su ciclo de vida, vencimiento, no-show, cancelación, precio estimado y prevención de conflictos.

Reservations consulta a Parking (`ParkingInfrastructureDirectory`, `ParkingSpaceAllocator`), a Vehicles (`VehicleDirectory`), a Customers (`CustomerDirectory`) y a Tariffs (`PricingEngine`). ParkingSessions usa `ReservationDirectory` en la entrada, la salida y la anulación. Reservations no depende de ParkingSessions.

`SpaceCompatibility` (qué espacio admite qué vehículo) pasó de ParkingSessions a Parking. Reservas también la necesita, y dejarla en sesiones habría creado una dependencia circular entre los dos módulos.

## Qué se puede prometer

Solo los espacios marcados como **reservables** y en operación forman la bolsa de reservas. No entran los bloqueados, en mantenimiento ni fuera de servicio.

- **Espacio**: se promete ese espacio. Nunca se promete dos veces para ventanas que se cruzan, con el colchón incluido.
- **Zona**: se promete capacidad dentro de la zona.
- **Parqueadero**: se promete capacidad en cualquier espacio reservable del parqueadero.

La demanda de una ventana es el **pico** de reservas que coinciden en un mismo instante, no cuántas tocan la ventana: 08–10 y 10–12 comparten un espacio. Toda reserva cuenta contra el parqueadero; las de zona y de espacio también cuentan contra su zona. La bolsa se cuenta con los espacios que aceptan el tipo de vehículo, mientras que la demanda cuenta todas las reservas. Es una regla conservadora: puede rechazar algo que habría cabido, pero nunca promete de más.

## Concurrencia

Las reservas de un parqueadero se serializan con un `pg_advisory_xact_lock` por parqueadero, dentro de la transacción. No se bloquea la fila del parqueadero porque las entradas la referencian. En SQLite el motor ya serializa las escrituras.

Además, PostgreSQL decide con dos restricciones de exclusión (`btree_gist`):

- un espacio no tiene dos reservas activas con rangos bloqueados que se crucen;
- un vehículo no tiene dos reservas activas que se crucen en el mismo parqueadero.

Si algo se escapa de la aplicación, la base responde con `23P01` y la API con `409 RESERVATION_CONFLICT`. La creación acepta `Idempotency-Key`, con las mismas reglas que la entrada y la salida.

`btree_gist` es una extensión estándar de PostgreSQL. En producción debe estar disponible para el rol que ejecuta las migraciones.

## Reglas por parqueadero

`reservation_policies` guarda, por parqueadero:

- si recibe reservas;
- el colchón entre reservas;
- la retención de una reserva sin confirmar;
- la tolerancia de llegada;
- la duración mínima y máxima;
- la anticipación máxima;
- si deben caer dentro del horario de operación.

Un parqueadero sin fila usa los valores por omisión: 10, 15 y 30 minutos; de 30 minutos a 24 horas; hasta 60 días antes; dentro del horario.

Las horas derivadas se calculan una sola vez y quedan en la reserva: rango bloqueado, desde cuándo puede llegar y cuándo es no-show. Cambiar la política no mueve las reservas existentes. Se puede llegar hasta 15 minutos antes, o el colchón si es mayor.

El horario se verifica con `WeeklySchedule::covers`, incluidos los turnos que cruzan la medianoche.

## Ciclo de vida

`pending → confirmed → active → completed`, con `pending → expired`, `confirmed → no_show` y `pending|confirmed → cancelled`.

- Una reserva sin confirmar retiene su cupo hasta `hold_expires_at`.
- Cancelar exige un motivo y queda en la auditoría.
- La entrada del vehículo la pasa a `active`; la salida, a `completed`.
- Si la entrada se anula, vuelve a `confirmed`.

El comando `reservations:sweep` corre cada minuto en el scheduler. Vence retenciones, marca no-shows y **retiene el espacio** de las reservas confirmadas desde el inicio de su rango bloqueado (`available → reserved`). Crear, confirmar, cancelar y llegar también ajustan primero su parqueadero, así que la corrección no depende de que el scheduler esté al día.

## Llegada

La entrada busca por placa una reserva confirmada del parqueadero cuya ventana de llegada incluya el momento actual:

- Si la reserva es de un espacio, la entrada toma ese espacio aunque esté retenido. Si un visitante lo ocupó antes de que empezara la retención, el vehículo va a otro espacio de la zona o del parqueadero.
- Si es de zona, se asigna un espacio de esa zona.
- Las llegadas con reserva usan primero los espacios reservables; los visitantes los usan de últimos.

Un espacio retenido no se puede ocupar a mano ni cambiar de estado administrativamente.

## Precio

Al reservar, el precio se estima con el motor de tarifas para la ventana completa, con las tarifas vigentes al inicio, y se guarda con su desglose y versión. Es un estimado: la salida cobra la estancia real (ADR-016), y el prepago llega con pagos (0.9).

## Permisos

- `reservations.view`: ver reservas y disponibilidad. Todos los roles de sistema menos el contador.
- `reservations.manage`: crear, confirmar y cancelar. Propietario, administrador, supervisor y cajero.
- Cambiar las reglas de un parqueadero requiere `parking.update`.

## Pendiente

- Modificar una reserva (hoy se cancela y se crea otra).
- Prepago y cobro de no-shows (0.9).
- QR de reserva (0.14) y notificaciones al cliente (0.17).
- Un espacio que deja de ser reservable o sale de servicio no reubica sus reservas futuras: la llegada se asigna a otro espacio.
