# Arquitectura

Nuvora Cloud es un monolito modular sobre Laravel. El navegador habla con una aplicación Vue independiente que consume `/api/v1`.

PostgreSQL es el sistema de registro. Redis cubre caché, sesiones, colas y límites de tasa. Los timestamps se persisten en UTC.

Los módulos viven en `app/Modules` y no acceden a los internos de otros módulos. La versión 0.2 deja operativos Identidad, Tenancy y la base de Auditoría, además de los componentes compartidos de salud, correlación y errores. El resto de los módulos existe como límite, sin reglas de negocio todavía.

Las decisiones están en `docs/adr`.
