# ADR-004: Redis

Redis se usa para caché, sesiones, colas y límites de tasa. No es la fuente de verdad del negocio. Si Redis no responde, `/health/ready` queda degradado y las operaciones de negocio no se confirman solo en memoria.
