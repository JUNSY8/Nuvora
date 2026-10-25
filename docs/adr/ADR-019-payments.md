# ADR-019: Pagos

La versión 0.9 agrega el módulo Payments:

- efectivo y pagos manuales;
- estados del pago;
- idempotencia;
- reembolsos;
- historial;
- recibos;
- cierre de caja.

El dominio queda listo para pasarelas en línea (0.10) sin cambiar a quien cobra.

## Qué se paga

Payments no conoce las tablas de los módulos que cobran. Cada módulo implementa `PayableSource`, el contrato de Payments, y se registra en el contenedor (`AppServiceProvider`, etiqueta `payments.payable-sources`):

| Tipo | Módulo | Valor | Al pagarse |
|---|---|---|---|
| `parking_session` | ParkingSessions (`SessionPayables`) | total de una estancia cerrada `priced` | nada que guardar: el saldo se lee de Payments |
| `membership_period` | Memberships (`PeriodPayables`) | valor del periodo | `due` → `paid`, y vuelve a `due` si un reembolso deja saldo |

`lock` carga el cobro del tenant y lo bloquea hasta el final de la transacción. `settled` le avisa al módulo el nuevo saldo.

Una estancia abierta, anulada, cubierta por mensualidad, sin tarifa o en gracia no tiene valor a pagar y responde `NOTHING_DUE`. El cobro no tiene llave foránea porque es polimórfico; la tabla guarda `payable_type` y `payable_id`, más una copia (`details`) de lo que muestra el recibo.

ParkingSessions y Memberships consultan los saldos con `PaymentDirectory`. Payments no depende de ellos: solo de su contrato.

## Dinero, saldo y pagos parciales

- Los montos son enteros en la unidad menor, como en las tarifas (ADR-016), y deben ser cobrables en la moneda (pesos enteros).
- Saldo = valor − (pagos recibidos − reembolsos).
- Un cobro admite varios pagos: un pago parcial deja el resto pendiente. Sin valor, el pago cubre todo el saldo.
- Nunca se paga más del saldo (`AMOUNT_EXCEEDS_BALANCE`). Un cobro saldado responde `ALREADY_PAID`.

**Concurrencia.** El cobro queda bloqueado mientras se lee su saldo y se guarda el pago. Dos cajeros cobrando la misma estancia se serializan y el segundo ve el saldo actualizado.

## Medios y abstracción

Medios:

- efectivo;
- tarjeta (datáfono externo);
- transferencia;
- otro.

Los tres últimos son pagos manuales y guardan la referencia de la operación. El efectivo registra lo recibido y las vueltas. Lo recibido no puede ser menor que el valor (`INSUFFICIENT_TENDER`), y una `CHECK` exige `vueltas = recibido − valor`.

Quien mueve el dinero es un puerto, `PaymentGateway`, con `charge` y `refund`. Hoy existe solo `OfflineGateway`, que completa al instante porque el cajero ya tiene el dinero. `PaymentGateways` elige el gateway por medio y el pago guarda `provider` y `provider_reference`. En 0.10 los adaptadores en línea se registran delante del offline y pueden responder `pending`.

## Estados

```text
pending ─► completed ─► partially_refunded ─► refunded
   └────► failed
```

Solo `completed`, `partially_refunded` y `refunded` cuentan como dinero recibido. PostgreSQL verifica que el estado coincida con lo reembolsado (`refunded_amount`).

## Idempotencia

Cobrar y reembolsar aceptan `Idempotency-Key`, única por tenant:

- un reintento con la misma clave devuelve el resultado original (`replayed: true`) sin cobrar dos veces;
- una clave reutilizada para otro cobro responde `IDEMPOTENCY_KEY_REUSED`;
- si dos reintentos llegan a la vez, el índice único decide y el perdedor responde como repetición.

La interfaz genera una clave por intento, como en entradas y salidas.

## Cobro en la salida

`POST /parking-sessions/{id}/exit` acepta `payment` (medio, efectivo recibido, referencia). El pago se registra en la misma transacción que la salida:

- si el efectivo no alcanza, el vehículo sigue adentro;
- si la estancia no tiene valor a pagar, el pago se omite;
- la clave del pago se deriva de la clave de la salida, y una salida repetida no vuelve a cobrar;
- registrar la salida con pago exige `payments.create` además de `sessions.close`.

El operador, sin ese permiso, registra la salida sin pago y la estancia queda **por cobrar**: el historial la filtra (`unpaid=1`) y la operación cuenta `unpaid_today`. El resumen agrega `collected_today`, lo recaudado hoy en el parqueadero menos los reembolsos. `charged_today` sigue siendo lo calculado en las salidas.

### Precio fijado

La cotización devuelve `quote_token`, válido por `NUVORA_QUOTE_HOLD_MINUTES` minutos (5 por defecto). Si la salida lo trae vigente, se cobra con el instante de la cotización: el conductor paga lo que se le dijo aunque mientras tanto cumpla otra fracción. La duración registrada sigue siendo la real.

- El token va cifrado con la llave de la aplicación: el cliente no puede leerlo ni inventar el instante.
- Debe ser de la misma empresa, la misma sesión y el mismo descuento (`INVALID_QUOTE`).
- Vencido, se ignora y la salida calcula en su propio momento.

La interfaz no recalcula mientras el precio está fijado.

## Reembolsos

Un reembolso (`payments.refund`, solo el propietario por defecto):

- devuelve todo o parte de un pago por el mismo medio;
- exige motivo;
- tiene número propio (`DV-…`);
- nunca supera lo que queda del pago;
- reabre el saldo del cobro, que se puede cobrar de nuevo.

Bloquea el cobro antes que el pago, el mismo orden que usa cobrar, para no crear interbloqueos.

## Recibos

Cada pago tiene un consecutivo por empresa (`RC-000001`), con contadores en `receipt_sequences` bloqueados dentro de la transacción. Un número confirmado nunca se repite y un cobro revertido devuelve su número.

`GET /payments/{id}/receipt` arma el recibo desde la copia guardada en el pago: empresa, parqueadero, detalle de la estancia o del periodo, medio, recibido, vueltas y reembolsos. La interfaz lo imprime en una página sin el marco de la aplicación. Es un comprobante de pago, no una factura electrónica (0.11): no discrimina impuestos.

## Cierre de caja

Un cierre (`CC-…`) toma todos los pagos que recibió un cajero y los reembolsos que hizo desde su último cierre, por moneda. Suma por medio lo recibido, lo reembolsado y el neto, y compara el efectivo esperado con el contado. La diferencia (`contado − esperado`) queda guardada y una `CHECK` la verifica.

Los pagos y reembolsos incluidos se bloquean y se marcan con el cierre en la misma transacción, así que un movimiento entra en un solo cierre. Un reembolso sale de la caja de quien lo hace y cuenta en el cierre de esa persona, no en el del cajero que cobró.

- Cerrar la propia caja exige `payments.create`.
- Cerrar la de otro cajero exige `payments.reconcile` (propietario, administrador, supervisor).

La conciliación contra extractos de pasarelas llega con 0.10.

## Mensualidades

Los periodos se cobran desde el detalle de la mensualidad y el detalle muestra el saldo pendiente. Los días de gracia son el margen para cobrar. **Desde 0.10** la cobertura sí depende del pago: pasado ese margen, un periodo sin pagar deja de cubrir (ADR-020).

## Permisos

| Permiso | Qué permite | Roles de sistema |
|---|---|---|
| `payments.view` | historial, recibos y cierres | propietario, administrador, supervisor, cajero, contador |
| `payments.create` | cobrar y cerrar la propia caja | propietario, administrador, cajero |
| `payments.refund` | reembolsar | propietario |
| `payments.reconcile` | cerrar la caja de cualquier cajero | propietario, administrador, supervisor |

Una migración concede los permisos a los roles de sistema existentes.

## Auditoría

`payment.collected`, `payment.refunded` y `payment.reconciled`. La salida audita además el `payment_id` cobrado con ella.

## Pendiente

- Pasarelas en línea, webhooks, conciliación con la pasarela y reembolsos asíncronos: hechos en 0.10 (ADR-020).
- Factura electrónica e impuestos (0.11).
- Cobro anticipado y penalidades de reservas (0.14).
- Prorrateo de mensualidades al cambiar de plan.
- Anular un pago el mismo día sin pasar por reembolso.
