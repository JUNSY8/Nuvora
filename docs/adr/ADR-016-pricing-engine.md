# ADR-016: Motor de tarifas

La versión 0.6 agrega el módulo Tariffs: tarifas por parqueadero, descuentos y promociones, simulador e historial de versiones. La salida calcula y guarda el cobro; el recaudo llega con pagos (0.9).

Tariffs es un módulo propio. Consulta a Parking por `ParkingInfrastructureDirectory` (moneda, zona horaria, gracia y zona del espacio). ParkingSessions le pide precios por `PricingEngine`, su contrato público. Tariffs no depende de ParkingSessions.

## Dinero

Los montos son enteros en la unidad menor de la moneda (ISO 4217): 5.000 COP viajan y se guardan como 500000. Es el mismo formato que usan las pasarelas de pago. `Currency` (Shared) define el exponente y el paso cobrable: el peso colombiano se cobra en pesos enteros, así que precios y descuentos calculados son múltiplos de 100. Nunca se usa `float`.

La tarifa guarda la moneda del parqueadero y una llave compuesta `(parking_lot_id, currency) → parking_lots(id, currency)` con `ON UPDATE RESTRICT`. Un parqueadero con tarifas no puede cambiar de moneda (`CURRENCY_IN_USE`): sus montos cambiarían de significado.

## Forma de una tarifa

Una sola estructura cubre los modelos de la versión:

- **Tramos acumulados**: precio total de la estancia hasta cierto tiempo (hasta 15 min $2.000, hasta 30 min $3.000…). El primer tramo que cubre la estancia es el precio.
- **Fracción adicional**: después del último tramo (o desde el minuto cero), cada fracción iniciada suma su valor. Una hora es la tarifa por hora; 15 minutos, la fraccional; 1.440 minutos, la diaria.
- **Tope por 24 horas** opcional: cada bloque de 24 h contado desde la entrada cuesta como máximo ese valor.

La tarifa progresiva (primera hora $5.000, segunda $4.000, adicionales $3.000) se expresa con tramos. Los tramos deben crecer en minutos y no bajar de precio: una estancia más larga nunca cuesta menos.

Se descartó un lenguaje de reglas configurable: es difícil de validar, de explicar al cliente y de probar. Si un modelo futuro (eventos, precios dinámicos) no cabe, se agrega como otra estrategia de `RateSchedule`.

## Qué tarifa aplica

Cada tarifa puede limitarse por tipo de vehículo, por zona y por franjas semanales en hora local del parqueadero. La **hora de entrada** decide la franja: quien entra a las 20:00 paga la tarifa nocturna aunque salga de día. Una franja que termina antes de empezar cruza la medianoche y pertenece al día en que empieza.

Si varias aplican, gana la de mayor prioridad; con igual prioridad, la más específica (zona 4, franja 2, vehículo 1); con igual especificidad, el código menor. La elección no depende del orden de las consultas.

La zona es la zona actual del espacio de la sesión. Una sesión sin espacio solo usa tarifas sin zona.

## Versiones e historial

Una tarifa nunca se reescribe. Editarla, activarla o desactivarla inserta una versión nueva e inmutable (`tariff_versions`) con `effective_from`. La sesión se cobra con las versiones **vigentes cuando entró**: subir el precio no alcanza a quien ya está dentro. Si al entrar no había ninguna tarifa aplicable (por ejemplo, tarifas creadas después), se usan las vigentes a la salida. Guardar la misma configuración no publica versión.

La sesión cerrada guarda la tarifa, la versión, el descuento, los minutos cobrados, subtotal, descuento, total y el desglose en `pricing`, con etiquetas legibles. Así el cobro se explica aunque la tarifa cambie después. Un `CHECK` en PostgreSQL exige `total = subtotal - descuento` en las sesiones cobradas.

Las tarifas y los descuentos no se eliminan; se desactivan. Las zonas usadas por una versión de tarifa no se pueden eliminar (`ZONE_IN_USE`).

## Gracia, descuentos y promociones

- Una estancia dentro del tiempo de gracia cuesta cero y no toma descuentos. La gracia es la del parqueadero; una tarifa puede definir la suya.
- Los descuentos son porcentaje (puntos básicos, redondeo a la mitad hacia arriba al paso de la moneda), valor fijo (en su moneda) o minutos gratis (se restan antes de aplicar la tarifa).
- **Manuales**: el cajero los elige en la salida (convenios, cortesías). Requieren el permiso `sessions.discount`.
- **Automáticos** (promociones): se aplican solos si están vigentes según la fecha y el día de la semana de la salida, en hora local.
- No se acumulan: aplica el elegido por el cajero o, si no eligió, la promoción que más descuenta. El total nunca baja de cero.

## Salida sin tarifa

Si ninguna tarifa aplica, la salida se registra igual con `charge_status = unpriced` y sin montos. Bloquear la salida dejaría el vehículo atrapado por un problema de configuración. El resumen de operación cuenta esas salidas y la pantalla de tarifas avisa cuando no hay ninguna activa. Las sesiones cerradas antes de 0.6 quedaron como `unpriced`.

## Cotización y simulador

`GET /parking-sessions/{id}/quote` muestra cuánto costaría salir ahora, sin guardar nada. La salida vuelve a calcular en su propio momento, así que un minuto después puede sumar una fracción. Con pagos (0.9) la cotización podrá fijarse por unos minutos.

`POST /tariffs/simulate` usa el mismo motor con entrada y salida en hora local. Una fecha pasada muestra las tarifas que estaban vigentes entonces.

## Permisos

- `tariffs.view`: ver tarifas, descuentos y el simulador. Todos los roles de sistema lo reciben.
- `tariffs.manage`: configurar tarifas y descuentos. Propietario y administrador.
- `sessions.discount`: aplicar un descuento manual en la salida. Propietario, administrador, supervisor y cajero; el operador no.

Una migración los concede a los roles de sistema existentes.

## Pendiente

- Festivos y calendarios (tarifas por fecha), membresías (0.8) y tarifas de reserva (0.7).
- Impuestos y factura electrónica (0.11): hoy el total no discrimina IVA.
- Precio fijado al cobrar y recaudo (0.9).
