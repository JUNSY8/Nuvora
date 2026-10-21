# Arquitectura

Nuvora Cloud es un monolito modular sobre Laravel. El navegador habla con una aplicación Vue independiente que consume `/api/v1`.

PostgreSQL es el sistema de registro. Redis cubre caché, sesiones, colas y límites de tasa. Los timestamps se persisten en UTC.

Los módulos viven en `app/Modules` y no acceden a los internos de otros módulos. La versión 0.2 dejó operativos Identidad, Tenancy y la base de Auditoría, además de los componentes compartidos de salud, correlación y errores. La versión 0.3 agrega Parking (sedes, horario, zonas, espacios y puertas) y el registro de Devices. La versión 0.4 agrega Customers y Vehicles, con normalización de placas por país, vínculos cliente-vehículo e historial del vehículo. El resto de los módulos existe como límite, sin reglas de negocio todavía.

Las decisiones están en `docs/adr`.

La interfaz usa el sistema visual Velzon oscuro (tokens en `frontend/src/app/styles.css`, componentes en `frontend/src/components/ui`) y los íconos de Remix Icon, el set que usa Velzon, instalado como dependencia npm. No se copian archivos del tema.
