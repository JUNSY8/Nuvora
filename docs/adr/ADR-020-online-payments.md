# ADR-020: Pagos en línea

La versión 0.10 conecta Payments con pasarelas en línea: Wompi y PayU, por enlace de pago, con notificaciones firmadas, sincronización con reintentos, reembolsos por la pasarela y conciliación contra ella. También aplica la regla de cobertura de mensualidades por pago.

## Cuentas por empresa

Cada empresa cobra en su propia cuenta de la pasarela (`payment_provider_accounts`): proveedor, nombre, ambiente (pruebas o producción), estado y credenciales.

- Las credenciales se guardan cifradas con la llave de la aplicación (`encrypted:array`). Ninguna respuesta las devuelve: solo los últimos cuatro caracteres. Un campo vacío al editar conserva el valor guardado.
- La auditoría registra qué credenciales cambiaron, nunca su valor.
- Una cuenta no cambia de proveedor; se crea otra.
- `payments.configure` (propietario y administrador) conecta y edita cuentas. Quien cobra (`payments.create`) solo ve las activas para enviar enlaces.

## Adaptadores

El puerto `OnlinePaymentProvider` (Domain) define cinco operaciones: checkout alojado, verificación de notificaciones, consulta de transacción, reembolso y seguimiento del reembolso. Cada adaptador (Infrastructure) traduce los códigos, firmas y URLs de su proveedor a formas normalizadas: `ProviderTransaction`, `ProviderNotification` y `ProviderRefund`, con estados `approved`, `pending`, `declined`, `expired`, `error` o `voided`. Nada fuera del adaptador conoce un nombre de campo de un proveedor.

| | Wompi | PayU |
|---|---|---|
| Checkout | Web Checkout por redirección (GET) | WebCheckout por formulario (POST) |
| Firma del checkout | SHA256(referencia + centavos + moneda + vencimiento + secreto de integridad) | MD5(apiKey~merchantId~referencia~valor~moneda) |
| Notificación | evento JSON; checksum SHA256 de `signature.properties` + timestamp + secreto de eventos | página de confirmación; `sign` MD5 (o SHA256) con `new_value` |
| Consulta | `/transactions/{id}` o `?reference=` con llave privada | Reports API `ORDER_DETAIL_BY_REFERENCE_CODE` |
| Reembolso | anulación (`void`) de pagos con tarjeta, completa | `SUBMIT_TRANSACTION` tipo `REFUND`, completo y revisado por PayU |

El cliente paga en la página de la pasarela con tarjeta, PSE, Nequi u otro medio. Nuvora nunca ve datos de tarjetas. El medio que la pasarela informa (`provider_method`) se traduce al de Nuvora: tarjeta, transferencia u otro.

Las llamadas usan tiempos de espera cortos y reintentan una vez ante errores de red o 5xx (`ProviderHttp`). Si la pasarela sigue sin responder, la operación lanza `ProviderUnavailable` (503) y no cambia nada en Nuvora.

## Enlace de pago

Un cajero crea un enlace para una estancia cerrada o un periodo de mensualidad (`POST /payments/checkouts`). Se crea un pago `pending`, sin número de recibo y sin cajero (`received_by` vacío; `requested_by` es quien creó el enlace), con vencimiento (`NUVORA_PAYMENT_LINK_MINUTES`, 60 por defecto).

- **El token del enlace es la llave de acceso.** Son 48 caracteres aleatorios, se guarda su hash para buscarlo y una copia cifrada para que el personal pueda volver a copiarlo.
- La página pública `/pagar/{token}` no exige sesión. Muestra empresa, concepto y valor, y arma el checkout de la pasarela en el momento.
- La referencia que se envía a la pasarela es el id del pago: único, y la pasarela la devuelve en cada notificación.
- Mientras un enlace esté pendiente no se abre otro para el mismo cobro (`PAYMENT_PENDING`), así dos aprobaciones no pagan dos veces.
- Si el cobro se pagó por otro medio, la página deja de ofrecer el pago.
- Anular un enlace consulta antes a la pasarela: si ya se aprobó, se confirma en lugar de anularse. Anular no lo invalida en la pasarela; si el cliente paga igual, el pago se registra.

## Una sola forma de aplicar respuestas

Webhook, sincronización y conciliación pasan por `ApplyProviderResult`, el único lugar donde un pago en línea cambia de estado:

- bloquea primero el cobro y después el pago, el mismo orden de todo pago;
- una aprobación con otro valor o moneda no se acepta (`amount_mismatch`) y queda anotada;
- una aprobación le da al pago su número de recibo (`RC-…`), lo marca recibido y salda el cobro;
- las respuestas repetidas o desordenadas no cambian nada: una pendiente nunca deshace una final;
- **una aprobación tardía** (después de vencido, rechazado o anulado) cuenta, porque el dinero se cobró. Si el cobro ya estaba pagado, el saldo queda `overpaid` y la conciliación lo señala para reembolsar;
- una anulación de un pago recibido se registra como reembolso.

## Notificaciones (webhooks)

`POST /api/v1/webhooks/payments/{provider}/{account}` va sin sesión ni CSRF, en el grupo `api`.

- La URL nombra la cuenta, pero solo la firma hecha con el secreto de esa cuenta la vuelve confiable. La empresa sale de la cuenta, nunca del cuerpo.
- El pago se busca dentro de esa cuenta: una notificación no puede tocar el pago de otra empresa.
- Cada notificación verificada queda en `payment_provider_events`, con una clave única por cuenta: el checksum en Wompi, transacción + estado en PayU. Un reintento de la pasarela se reconoce y no se aplica dos veces.

| Respuesta | Cuándo |
|---|---|
| 404 | Cuenta desconocida |
| 401 | Firma inválida (se registra una advertencia, sin datos) |
| 200 | Notificación registrada, incluidas las duplicadas y las de referencias desconocidas, para que la pasarela deje de reintentar |
| 500 | Falló al aplicarse; la pasarela reintenta |

## Sincronización y reintentos

`payments:sync` corre cada cinco minutos y es la red de seguridad cuando un webhook no llega:

- pregunta por cada pago pendiente con espera creciente (2, 4, 8… hasta 60 minutos);
- da por vencido el enlace que pasó su vencimiento sin transacción, con 15 minutos de margen para una aprobación tardía;
- sigue los reembolsos que la pasarela dejó en proceso.

Una pasarela caída deja el pago pendiente para la próxima vuelta. Al volver de la pasarela, la página pública consulta el resultado como mucho cada 15 segundos.

## Reembolsos en línea

- **Por la pasarela** (canal por defecto): el reembolso se reserva y se confirma en una transacción (`pending`, y el valor ya cuenta como devuelto). Luego se llama a la pasarela fuera de la transacción.
  - Si aprueba, queda `completed`.
  - Si rechaza, queda `failed` y el valor vuelve al pago y al saldo.
  - Si no responde, sigue `pending` y la sincronización lo resuelve.
  - Wompi y PayU solo devuelven el pago completo (`PARTIAL_REFUND_UNSUPPORTED`).
- **A mano**: efectivo o una transferencia hecha fuera de Nuvora, para valores parciales o pasarelas que no reembolsan. El efectivo sale de la caja de quien lo hace.

## Cierre de caja

Los pagos en línea y sus reembolsos por la pasarela no pasan por ninguna caja y no entran en el cierre (ADR-019). Un reembolso manual en efectivo sí entra.

## Conciliación con la pasarela

`POST /payment-providers/{account}/reconcile` (`payments.reconcile`) revisa los pagos de la cuenta en un rango de días locales (hasta 200) contra la pasarela:

- aplica lo que se resuelve solo: un pendiente ya aprobado o rechazado, una aprobación tardía, una anulación;
- reporta el resto: `not_found`, `status_mismatch`, `amount_mismatch`, `overpaid` y `unreachable`.

No guarda un reporte: el resultado se muestra y la revisión queda en la auditoría.

## Mensualidades: cobertura según el pago

Cambia lo definido en ADR-018. Una llegada cubierta necesita el periodo de ese día **pagado** una vez pasan los días de gracia, contados desde el primer día del periodo. Sin pago, el vehículo entra como visitante y el operador recibe el aviso `MEMBERSHIP_UNPAID`. Un pago parcial no basta. La regla es `AccessRules::requirePayment`.

## Permisos

| Permiso | Qué permite | Roles de sistema |
|---|---|---|
| `payments.configure` | conectar y editar cuentas de pasarela | propietario, administrador |
| `payments.create` | crear y anular enlaces de pago | según ADR-019 |
| `payments.reconcile` | conciliar con la pasarela | según ADR-019 |

## Auditoría

- `payment_provider.created` y `payment_provider.updated`
- `payment.link_created` y `payment.link_cancelled`
- `payment.confirmed`, `payment.failed`, `payment.voided` y `payment.amount_mismatch`: sin actor, con el origen (`webhook`, `sync`, `reconciliation`)
- `payment.provider_reconciled`

## Pendiente

- PSE directo (API, sin checkout alojado), tokenización de tarjetas y cobros recurrentes de mensualidades (cobro automático).
- Más proveedores, que entran como otro adaptador más en `OnlineProviders`.
- Notificar al cliente el enlace por correo, SMS o WhatsApp (0.17); hoy el cajero lo copia.
- Factura electrónica (0.11).
