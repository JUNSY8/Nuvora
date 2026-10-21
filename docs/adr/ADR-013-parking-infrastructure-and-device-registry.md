# ADR-013: Infraestructura de parqueaderos y registro de dispositivos

La versión 0.3 representa la infraestructura física: parqueaderos (sedes), horario semanal, zonas, espacios, puertas y dispositivos. Parking es dueño de los primeros; Devices es un módulo aparte porque su ciclo de vida sigue a Nuvora Edge y no a la configuración del parqueadero.

## Aislamiento en la base de datos

Cada tabla tiene `tenant_id`. Las hijas apuntan al parqueadero con una llave foránea compuesta `(tenant_id, parking_lot_id) → parking_lots(tenant_id, id)`, así la base impide que una zona, espacio, puerta o dispositivo quede bajo un parqueadero de otra empresa aunque la aplicación falle. Del mismo modo, `(parking_lot_id, zone_id)` y `(parking_lot_id, gate_id)` impiden asignar una zona o puerta de otro parqueadero. Las tablas nuevas activan la misma política RLS de ADR-012.

La aplicación sigue cargando cada recurso por `tenant_id` del contexto. Un recurso de otra empresa responde 404.

## Estados

Un espacio usa una máquina de estados explícita (`SpaceStatus`). Ocupado y reservado pertenecen a sesiones (0.5) y reservas (0.7); por eso el cambio administrativo solo se mueve entre disponible, bloqueado, mantenimiento y fuera de servicio. El cambio bloquea la fila (`lockForUpdate`) para no intercalarse con un ingreso concurrente, y repetir el estado actual es idempotente y no genera auditoría. La API devuelve `manual_transitions` para que la interfaz no duplique la regla.

La capacidad excluye los espacios fuera de servicio y no se almacena: se calcula con una consulta agrupada por parqueadero y estado.

Un dispositivo nace `unknown`. `online`, `offline` y `degraded` los reportará Nuvora Edge; una persona solo puede llevarlo a mantenimiento o devolverlo a monitoreo (`unknown`).

## Horario

El horario se guarda como siete filas, una por día ISO, en hora local de la zona del parqueadero (`timezone`). Un cierre anterior a la apertura cruza la medianoche. Se reemplaza completo en una transacción con la fila del parqueadero bloqueada.

## Borrado

Los borrados son restrictivos. Un parqueadero con espacios, puertas o dispositivos, una zona con espacios o una puerta con dispositivos no se pueden eliminar; para dejar de operar un parqueadero se marca inactivo. Las violaciones de llave foránea se traducen a una regla de negocio (422). Cada escritura vigilada corre en su propia transacción, que es un savepoint cuando hay una externa, porque PostgreSQL aborta la transacción completa cuando falla una restricción.

## Permisos

Parking usa `parking.view/create/update/delete`. Devices introduce `devices.view` y `devices.manage`. Una migración concede los nuevos permisos a los roles de sistema existentes (propietario y administrador: ambos; supervisor: ver) para que las empresas creadas antes de 0.3 queden iguales a las nuevas.

## Credenciales de dispositivos

El registro no acepta ni guarda usuario, contraseña ni tokens de cámaras o controladores. Esas credenciales vivirán cifradas en Nuvora Edge (0.13 y 0.20). El código del dispositivo es único por empresa porque Edge reporta los eventos con ese código.
