# ADR-014: Clientes, vehículos y normalización de placas

La versión 0.4 agrega el dominio de clientes y vehículos. Customers y Vehicles son módulos separados: un vehículo puede existir sin cliente (visitante frecuente) y un cliente puede no tener vehículos (empresa con convenio). Vehicles depende de Customers a través de `CustomerDirectory`; Customers no conoce a Vehicles, así no hay dependencias circulares. La ficha del cliente consulta sus vehículos con `GET /vehicles?customer_id=`.

## Clientes y empresas

Un cliente es `individual`, `business` o `corporate`. Las empresas y corporativos son organizaciones: una persona puede vincularse a una de ellas con `business_id`, por ejemplo los empleados de una flota. La llave foránea compuesta `(tenant_id, business_id) → customers(tenant_id, id)` impide vincularla a una organización de otra empresa. Una organización con personas vinculadas no puede convertirse en persona.

El tipo de documento es un código libre (CC, NIT, PAS...), no una lista colombiana fija. El número se compara normalizado (solo letras y dígitos) con un índice único `(tenant_id, document_type, document_normalized)`, así `1.020.304` y `1020304` son el mismo documento. El mismo número puede existir en otra empresa.

Los clientes no se eliminan; se inactivan. Un cliente inactivo conserva sus vínculos pero no recibe vínculos nuevos. La eliminación o anonimización por solicitud del titular (habeas data) queda pendiente y debe resolverse antes de producción.

Nombre, documento, correo y teléfono son datos personales. La auditoría de clientes registra qué campos se dieron o cambiaron, no sus valores.

## Placas

Una placa se guarda en forma canónica: mayúsculas, solo letras y dígitos, entre 2 y 12 caracteres (`Plate`). `abc-123`, `ABC 123` y `ABC123` son la misma placa. La unicidad es por empresa y país emisor `(tenant_id, country, plate)`, porque dos países pueden emitir los mismos caracteres. El país es un código ISO 3166-1 alfa-2 que envía el cliente; no se deduce del tenant.

Los formatos de cada país viven detrás de la interfaz `PlateRules`. Colombia (`ColombiaPlateRules`) reconoce automóvil, motocicleta (actual y anterior), motocarro, diplomático, remolque y temporal, y sugiere el tipo de vehículo. Un formato desconocido no rechaza la placa: las placas extranjeras y especiales deben poder registrarse. La interfaz advierte cuando una placa de un país con reglas no coincide con ningún formato. Agregar un país es agregar una clase al registro `CountryPlateRules`, sin tocar el dominio.

`GET /vehicles/plate-check` devuelve la placa canónica, el formato y si la empresa ya la tiene. La interfaz lo usa mientras se escribe y la operación manual de 0.5 lo reutilizará para identificar vehículos en la entrada.

## Tipos de vehículo

El tipo es un enum del dominio (automóvil, motocicleta, bicicleta, camioneta, camión, bus, otro) más el indicador `is_electric`. Un catálogo configurable por empresa no aporta todavía: las tarifas (0.6) y los espacios ya distinguen por tipo y un enum mantiene las reglas explícitas.

## Relación cliente-vehículo

`customer_vehicles` relaciona clientes y vehículos con una relación `owner`, `driver` o `authorized`. Hay a lo sumo un propietario activo por vehículo y un vínculo activo por par cliente-vehículo. Ambas reglas las garantizan índices únicos parciales (`WHERE ended_at IS NULL`), además de la validación en la aplicación, que bloquea la fila del vehículo para serializar cambios concurrentes.

Reemplazar al propietario exige `transfer_ownership: true`: el vínculo anterior se cierra y el nuevo propietario queda vinculado en la misma transacción. Si el cliente ya estaba vinculado con otra relación, se actualiza ese vínculo en lugar de crear otro.

Los vínculos nunca se borran: terminar uno pone `ended_at`. Así el historial explica quién fue propietario y cuándo.

## Historial del vehículo

`vehicle_events` es la línea de tiempo operativa del vehículo: registro, cambios de datos, corrección de placa, cambios de estado y vínculos con clientes. Los eventos guardan una copia del nombre del cliente para que el historial siga siendo legible si el cliente cambia. Las sesiones (0.5), reservas (0.7) y lecturas LPR (0.14) agregarán sus propios tipos de evento.

No reemplaza la auditoría. La auditoría responde quién hizo qué por seguridad y requiere `audit.view`. El historial es información de operación que ve cualquiera con `vehicles.view`.

## Estados

Un vehículo está `active`, `inactive` o `blocked`. Bloquear exige un motivo, que se guarda en el vehículo y en el historial. Una restricción CHECK lo garantiza en PostgreSQL. El control de acceso (0.12) negará el ingreso de vehículos bloqueados.

## Permisos

Customers introduce `customers.view`, `customers.create` y `customers.update`. Vehicles usa los permisos existentes `vehicles.view`, `vehicles.create` y `vehicles.update`; vincular o desvincular clientes requiere `vehicles.update`. Una migración concede los nuevos permisos a los roles de sistema existentes: propietario y administrador reciben los tres; supervisor y cajero reciben `customers.view`.

## Aislamiento

Las cuatro tablas nuevas tienen `tenant_id`, llaves foráneas compuestas hacia `customers(tenant_id, id)` y `vehicles(tenant_id, id)`, y la política RLS de ADR-012. Un cliente o vehículo de otra empresa responde 404. Un cliente de otra empresa enviado en `customer_id` o `business_id` responde 422 sin revelar si existe.
