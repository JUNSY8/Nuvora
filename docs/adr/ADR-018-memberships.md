# ADR-018: Mensualidades

La versión 0.8 agrega el módulo Memberships: planes, venta de mensualidades a un cliente con sus vehículos, periodos de cobro, renovación manual y automática, suspensión, cancelación, vencimiento y la cobertura de las estancias en la entrada.

Memberships consulta a Parking (`ParkingInfrastructureDirectory`: parqueaderos, zonas y espacios asignables), a Vehicles (`VehicleDirectory`), a Customers (`CustomerDirectory`) y a Tenancy (`TenantDirectory`, zona horaria para el scheduler). ParkingSessions usa `MembershipDirectory` en la entrada, el precio y las referencias de la sesión. Memberships no depende de ParkingSessions; de Tariffs solo reutiliza el valor `TimeWindow`, para que planes y tarifas describan las franjas igual.

## Plan y mensualidad

Un **plan** define precio por periodo, moneda, periodo de cobro (semanal, mensual, trimestral, anual), días de gracia, vehículos por mensualidad, cupos a la venta y reglas de acceso:

- parqueaderos;
- zonas;
- tipos de vehículo;
- franjas semanales en hora local, con la misma forma que las tarifas (`TimeWindow`).

Una lista vacía no restringe. La categoría (mensual, corporativo, VIP, empleados, residentes, estudiantes...) etiqueta y filtra. Un plan corporativo o VIP es una configuración, no código especial.

Una **mensualidad** es la venta de un plan a un cliente titular. Copia el precio, la moneda, el periodo y la gracia al venderse: si el plan cambia de precio, lo vendido no cambia. Las reglas de acceso sí se leen del plan vigente, para que un ajuste de horario o de zonas aplique a todos.

## Periodos

Los periodos son días locales completos anclados al día de inicio, así que nunca se corren: una mensualidad que empieza el 31 de enero tiene periodos 31 ene–27 feb, 28 feb–30 mar, 31 mar–29 abr... (`BillingPeriod::period`). Las fechas se guardan como `date`, y "hoy" es el día local de la empresa, nunca el del servidor (`MembershipCalendar::today`).

Cada periodo queda en `membership_periods` con su valor y estado `due`. El recaudo llega con pagos (0.9), que lo pasará a `paid`.

- **Cubre hasta** = fin del periodo + días de gracia.
- **Renovar antes de tiempo** agrega el siguiente periodo sin perder los días pagados.
- **Renovar una mensualidad vencida** empieza un ciclo nuevo hoy, en lugar de cobrar días que no se pudieron usar.
- **Renovar no levanta una suspensión.**

## Renovación sin doble cobro

Una renovación abre un periodo que se cobrará, así que no puede aplicarse dos veces:

- Acepta `Idempotency-Key`. La clave queda en el periodo (único por mensualidad), y un reintento con la misma clave devuelve la mensualidad sin abrir otro periodo ni auditar de nuevo.
- Sin clave, una mensualidad ya renovada por adelantado (el último periodo empieza después de hoy) responde `409 ALREADY_RENEWED`. Se renueva a lo sumo un periodo adelante.

La interfaz genera una clave por intento y la repite en los reintentos, como en entradas, salidas y reservas.

## Ciclo de vida

```text
active ◄──► suspended
  │  ▲         │
  │  └ renew ┐ │
  ├► expired ┘ │
  └► cancelled ◄┘
```

- Suspender y cancelar exigen motivo.
- Cancelar es definitivo: libera vehículos, espacio y cupo.
- Una mensualidad vencida se puede renovar.

El comando `memberships:renew` corre cada hora:

- abre el siguiente periodo de las mensualidades activas con renovación automática cuyo periodo terminó;
- vence las demás al acabar la gracia.

Trabaja por empresa con su día local y bloquea cada mensualidad. Correrlo dos veces no cambia nada. Todo cambio queda en la auditoría.

## Exclusividad y cupos

PostgreSQL garantiza con índices únicos parciales:

- un vehículo está en una sola mensualidad vigente (activa o suspendida);
- un espacio asignado tiene un solo titular vigente.

Los cupos de un plan se cuentan bajo bloqueo del plan al vender. Un espacio asignable (`is_assignable`) se usa de último para visitantes, como los reservables.

## Acceso en la entrada

`MembershipDirectory::accessFor` busca la mensualidad del vehículo y `AccessRules::evaluate` decide con la hora de llegada local:

- estado;
- inicio;
- cubre hasta;
- parqueadero;
- tipo de vehículo;
- franja.

La zona se verifica al conocer el espacio. Una llegada no cubierta **no se bloquea**: el vehículo entra como visitante y paga la tarifa, y la entrada devuelve `notices` con el motivo para el operador. Un miembro cubierto ocupa su espacio asignado si está libre, o uno de las zonas del plan. La sesión queda con `membership_id` y a nombre del titular de la mensualidad.

## Cobro de una estancia cubierta

La salida de una estancia cubierta igual se calcula con la tarifa, para registrar lo que la mensualidad pagó, pero cierra con `charge_status = covered`:

- total 0;
- subtotal igual al precio de la tarifa;
- una línea `membership` que lo descuenta;
- sin descuentos manuales ni promociones.

Una `CHECK` exige `membership_id` en los cobros `covered`. El resumen de operación cuenta las salidas cubiertas (`covered_today`).

## Permisos

- `memberships.view`: todos los roles de sistema.
- `memberships.manage` (vender, renovar, suspender, cancelar, editar planes): propietario, administrador, supervisor y cajero.

## Pendiente

- Cobro de los periodos, prorrateo y reembolsos (0.9).
- Integrar mensualidades con LPR y control de acceso de barreras (0.12, 0.13).
- Notificar vencimientos al cliente (0.17).
- Pasar una mensualidad a otro plan; hoy se cancela y se vende otra.
