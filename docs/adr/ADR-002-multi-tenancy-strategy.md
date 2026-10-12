# ADR-002: Multi-tenancy de esquema compartido

La estrategia inicial es una base compartida, un esquema compartido y `tenant_id` en las tablas que pertenecen a un tenant, con contexto de aplicación y Row Level Security en los datos críticos.

El tenant no se tomará del identificador enviado por el cliente. La versión 0.1 no persiste tenants todavía; esta decisión fija el modelo antes de crear esas tablas.
