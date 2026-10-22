# ADR-015: Sesiones de parqueo y actualización en vivo

La versión 0.5 agrega la operación manual: entrada, salida, asignación de espacio y el ciclo de vida de la sesión. ParkingSessions es un módulo propio. Usa a Parking por `ParkingSpaceAllocator` y `ParkingInfrastructureDirectory`, a Vehicles por `VehicleDirectory` y a Customers por `CustomerDirectory`. No lee los modelos de otros módulos.

## Ciclo de vida

Una sesión nace `open` y termina una sola vez: `closed` por la salida o `cancelled` cuando se registró por error. Las sesiones terminadas no se modifican; las correcciones llegarán como ajustes controlados. `paid` y `anomalous` llegan con pagos (0.9) y control de acceso.

La sesión guarda una copia de la placa, el país, el tipo de vehículo y el código del espacio al entrar. Así el historial sigue siendo legible aunque luego se corrija la placa o se renombre el espacio.

La duración se guarda en minutos y cuenta un minuto iniciado como uno completo. Las tarifas (0.6) aplicarán sus propias fracciones y el periodo de gracia del parqueadero a partir de `entry_at` y `exit_at`.

## Invariantes en la base de datos

- Un vehículo tiene a lo sumo una sesión abierta por parqueadero: índice único parcial `(parking_lot_id, vehicle_id) WHERE status = 'open'`.
- Un espacio tiene a lo sumo una sesión abierta: índice único parcial `(space_id) WHERE status = 'open'`.
- La llave compuesta `(parking_lot_id, space_id) → parking_spaces(parking_lot_id, id)` impide usar un espacio de otro parqueadero; lo mismo para las puertas. `(tenant_id, vehicle_id)` y `(tenant_id, customer_id)` impiden mezclar empresas.
- En PostgreSQL, un `CHECK` amarra el estado con sus fechas: abierta sin salida, cerrada con salida y duración, anulada con fecha y motivo.

La aplicación valida lo mismo antes de escribir para dar un mensaje claro. Cuando dos operadores compiten, decide el índice: la entrada perdedora recibe `409 ENTRY_CONFLICT`.

## Espacios

Ocupar y liberar pasan por la máquina de estados de Parking (`available → occupied → available`), dentro de la transacción de la sesión. La asignación automática bloquea la fila con `FOR UPDATE SKIP LOCKED` en PostgreSQL: dos entradas simultáneas reciben espacios distintos en vez de esperar la misma fila. La asignación automática solo usa espacios generales del tamaño del vehículo (los eléctricos prefieren carga). Los espacios para movilidad reducida, VIP y reservados solo se eligen a mano. La regla vive en `SpaceCompatibility`.

Se puede entrar sin espacio, para parqueaderos sin numeración o cuando el lote está lleno, y asignarlo después. Un espacio con historial de sesiones no se puede eliminar; se marca fuera de servicio.

La migración de 0.5 pasa a disponibles los espacios que estaban ocupados. Antes de 0.5 ningún flujo podía ocuparlos: venían del seeder de demostración y quedarían bloqueados sin una sesión que los liberara.

## Identificación manual

La entrada recibe la placa y el país. `VehicleDirectory::identifyOrRegister` busca el vehículo por placa canónica o registra un visitante con el tipo indicado, dentro de la misma transacción. Si la entrada falla, el visitante no queda registrado. Un operador sin `vehicles.create` puede admitir visitantes, porque la autorización es `sessions.create`. Un vehículo bloqueado no entra (`VEHICLE_BLOCKED`). El cliente de la sesión es el propietario activo o, si no hay, el vínculo más antiguo.

## Idempotencia

Entrada y salida aceptan el encabezado `Idempotency-Key`. Un reintento con la misma clave devuelve la sesión original con `replayed: true` y estado 200, sin repetir auditoría ni historial. Una clave reutilizada para otra placa, otro parqueadero u otra sesión responde `409 IDEMPOTENCY_KEY_REUSED`. Las claves son únicas por empresa (`entry_idempotency_key`, `exit_idempotency_key`). La interfaz crea una clave por intento y la renueva al cambiar de placa.

## Permisos

Se agregan `sessions.view` (pantallas de operación e historial) y `sessions.cancel` (anular). Anular es un permiso aparte de cerrar porque una estancia anulada nunca se cobra; exige motivo y queda auditada. Una migración concede los permisos nuevos a los roles de sistema existentes: `sessions.view` a propietario, administrador, supervisor, cajero, operador y consulta; `sessions.cancel` a propietario, administrador y supervisor.

## Actualización en vivo

El README propone Laravel Reverb. Instalarlo en 0.5 obligaba a bajar `guzzlehttp/psr7` de 3.x a 2.x en todo el proyecto y a operar un servidor WebSocket más. Se pospone.

En su lugar, `LiveUpdates` (Shared) guarda en la caché un marcador por canal (empresa y parqueadero) que se renueva después de cada commit que cambia la operación: entradas, salidas, anulaciones, cambios de espacio y cambios administrativos de espacios. `GET /api/v1/live` devuelve solo esos marcadores, nunca datos. Las pantallas lo consultan cada 4 segundos (el panel cada 10), pausan cuando la pestaña está oculta, espacian los reintentos tras errores y recargan por la API normal cuando el marcador cambia. Además recargan cada 60 segundos aunque no haya cambios, porque la caché puede perderse.

La base de datos sigue siendo la fuente de verdad: perder la caché solo cuesta una recarga. Si la caché falla, la operación de negocio no se interrumpe; queda un aviso en el log. Para pasar a WebSockets basta con que `LiveUpdates::touch` publique un evento y `useLiveUpdates` escuche el canal; los casos de uso no cambian.

## Pendiente

- Cobro al salir: el cálculo llegó con tarifas en 0.6 (ADR-016); el recaudo llega con pagos (0.9).
- Fuentes distintas de `manual`: LPR (0.12), dispositivos (0.13), QR (0.14).
- Una conciliación periódica que detecte espacios ocupados sin sesión abierta.
