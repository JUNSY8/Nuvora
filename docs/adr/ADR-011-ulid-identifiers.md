# ADR-011: Identificadores ULID

Las entidades nuevas usan ULID. `users.id` ya es ULID para no mezclar enteros y ULID cuando aparezcan las relaciones de tenant, sesiones y pagos.

Las tablas técnicas de Laravel que no son entidades de negocio, como `sessions` y `cache`, conservan el identificador que exige el framework.
