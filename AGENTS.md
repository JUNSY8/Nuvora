# Nuvora — AGENTS.md

## 1. Propósito

Nuvora es una plataforma web SaaS de gestión de parqueaderos, orientada a producción y diseñada para operar múltiples empresas (tenants), sedes y parqueaderos.

Este archivo es la guía maestra para agentes de IA y desarrolladores que trabajen en el repositorio.

El objetivo no es simplemente producir código que funcione, sino construir un sistema:

- Seguro.
- Multi-tenant de forma estricta.
- Mantenible.
- Testeable.
- Observable.
- Escalable.
- Resistente a errores y concurrencia.
- Preparado para integraciones con hardware.
- Adecuado para evolucionar hacia un producto comercial.

Las reglas específicas de `.cursor/rules/` y las habilidades de `.cursor/skills/` complementan este documento y deben respetarse.

---

# 2. Principios fundamentales

Antes de modificar código:

1. Inspeccionar el código existente.
2. Identificar el módulo responsable.
3. Entender dependencias y contratos existentes.
4. Determinar si el cambio afecta otros módulos.
5. Diseñar la solución antes de implementarla.
6. Implementar el cambio mínimo necesario.
7. Ejecutar las pruebas relevantes.
8. Verificar seguridad, aislamiento de tenant y consistencia de datos.
9. Revisar el diff antes de finalizar.

No se debe:

- Reescribir código sin necesidad.
- Crear abstracciones genéricas prematuramente.
- Mover lógica entre módulos solo para "limpiar" código.
- Introducir microservicios.
- Duplicar reglas de negocio.
- Ignorar errores silenciosamente.
- Deshabilitar pruebas para hacer pasar un cambio.
- Exponer secretos o credenciales.
- Confiar en identificadores enviados por el cliente para determinar el tenant.
- Usar `float` para dinero.
- Introducir dependencias sin justificar su necesidad.

---

# 3. Arquitectura

## 3.1 Arquitectura principal

Nuvora utiliza un **Modular Monolith**.

No convertir módulos internos en microservicios.

La estructura conceptual es:

```text
Nuvora Web
├── Laravel / PHP
├── Vue 3 / TypeScript
├── PostgreSQL
├── Redis
├── Realtime
└── Modular Monolith
```

Los módulos deben tener límites claros.

Estructura recomendada:

```text
app/
├── Modules/
│   ├── Identity/
│   ├── Tenancy/
│   ├── Parking/
│   ├── Access/
│   ├── Vehicles/
│   ├── Tariffs/
│   ├── Reservations/
│   ├── Memberships/
│   ├── Payments/
│   ├── Billing/
│   ├── Devices/
│   ├── Lpr/
│   ├── Notifications/
│   ├── Reporting/
│   ├── Audit/
│   └── Integrations/
│
└── Shared/
```

Cada módulo puede utilizar:

```text
Domain/
Application/
Infrastructure/
Presentation/
```

## 3.2 Dependencias

Preferir:

```text
Presentation
      ↓
Application
      ↓
Domain
      ↑
Infrastructure
```

El dominio no debe depender directamente de:

- Laravel HTTP.
- Controllers.
- Eloquent cuando pueda evitarse.
- Vue.
- PostgreSQL.
- Redis.
- SDKs de proveedores.
- APIs externas.
- Hardware.

Las dependencias externas deben aislarse mediante interfaces/puertos y adaptadores cuando el acoplamiento pueda comprometer el dominio.

---

# 4. Nuvora Web y Nuvora Edge

Nuvora tiene dos componentes principales:

```text
GitHub
├── nuvora
│   └── Web / Cloud
│
└── nuvora-edge
    └── Agente local de hardware
```

## 4.1 Nuvora Web

Responsabilidades:

- Usuarios.
- Tenants.
- Parqueaderos.
- Configuración.
- Tarifas.
- Reservas.
- Membresías.
- Sesiones de entrada/salida.
- Pagos.
- Reportes.
- Auditoría.
- Administración de cámaras y dispositivos.
- Estado de Edge.
- Reglas de negocio.

## 4.2 Nuvora Edge

Nuvora Edge es un agente local, no un microservicio del dominio empresarial.

Responsabilidades:

- Comunicación con cámaras.
- RTSP.
- ONVIF.
- HTTP.
- USB.
- Barreras.
- Lectores RFID/QR/barcode.
- Dispositivos seriales.
- Procesamiento local cuando corresponda.
- LPR/ANPR mediante proveedores/adaptadores.
- Cola local de eventos.
- Store-and-forward.
- Sincronización con Nuvora Cloud.
- Diagnóstico de hardware.

Tecnología objetivo:

- Python.
- FastAPI cuando sea necesaria una API local.
- SQLite.
- OpenCV.
- FFmpeg.
- ONVIF/RTSP.
- PySerial cuando corresponda.

Edge no debe convertirse en una segunda aplicación administrativa completa.

La configuración funcional y administrativa debe realizarse desde Nuvora Web.

---

# 5. Multi-tenancy

La separación entre tenants es una de las invariantes más importantes del sistema.

Modelo inicial:

```text
Shared Database
Shared Schema
tenant_id en entidades tenant-owned
```

Toda entidad que pertenezca a un tenant debe tener una estrategia explícita de aislamiento.

Nunca confiar en:

```text
tenant_id enviado por el frontend
```

como fuente de autoridad.

El tenant debe resolverse mediante contexto autenticado/autorizado.

Toda consulta debe ser evaluada desde el punto de vista:

> ¿Existe alguna posibilidad de que un usuario, request, job, webhook o proceso pueda leer o modificar datos de otro tenant?

Debe existir cobertura de pruebas para prevenir IDOR y cross-tenant access.

---

# 6. Identidad y autorización

Usar:

- Autenticación segura.
- Autorización basada en roles/permisos.
- Policies/gates o mecanismo equivalente.
- Principio de mínimo privilegio.
- MFA cuando corresponda.
- Auditoría de acciones sensibles.

Roles iniciales posibles:

- Platform Admin.
- Tenant Owner.
- Tenant Admin.
- Lot Manager.
- Supervisor.
- Cashier/Operator.
- Reservation Agent.
- Security.
- Maintenance.
- Auditor/Read Only.
- Custom roles.

No asumir que estar autenticado implica estar autorizado.

Toda operación de mutación debe verificar:

```text
Authentication
        ↓
Tenant context
        ↓
Authorization
        ↓
Validation
        ↓
Business rules
        ↓
Persistence
```

---

# 7. Dominio y reglas de negocio

La lógica de negocio debe vivir en el módulo correspondiente.

Evitar colocar reglas importantes en:

- Controllers.
- Vue components.
- Form Requests.
- Migrations.
- Jobs como sustituto de servicios de dominio.
- Helpers globales.
- Modelos gigantes.

Las reglas deben poder probarse sin depender de una interfaz concreta.

Ejemplos de invariantes importantes:

- Una reserva no puede ocupar capacidad inexistente.
- No debe existir doble reserva incompatible.
- Una sesión no puede cerrarse dos veces.
- Un pago procesado no debe aplicarse dos veces.
- Un evento de dispositivo duplicado no debe generar dos operaciones.
- Un usuario no puede acceder a recursos de otro tenant.
- Una plaza no puede aparecer disponible y ocupada simultáneamente dentro de una misma transacción válida.
- Una tarifa aplicada a una sesión debe conservar trazabilidad suficiente para explicar el cálculo.

---

# 8. Reservas y concurrencia

Las reservas son una funcionalidad crítica.

Toda implementación debe considerar:

- Capacidad.
- Ventanas de tiempo.
- Buffers.
- Hold temporal.
- Expiración.
- Cancelación.
- No-show.
- Check-in.
- Check-out.
- Sobreventa/overbooking.
- Concurrencia.
- Idempotencia.

No confiar solamente en:

```text
if available then create reservation
```

cuando existan requests concurrentes.

Usar mecanismos adecuados de base de datos/transacciones/locks/constraints según el caso.

---

# 9. Dinero y pagos

Nunca utilizar `float` para representar dinero.

Preferir:

- Enteros en unidades menores, o
- `DECIMAL` con precisión explícita.

La moneda debe estar definida explícitamente.

Los pagos deben ser idempotentes.

Nunca almacenar:

- CVV.
- Datos completos de tarjetas.
- Secretos de proveedores.

Los webhooks deben:

- Validarse.
- Autenticarse mediante firma cuando el proveedor lo soporte.
- Ser idempotentes.
- Registrar eventos relevantes.
- Manejar reintentos.
- Evitar doble aplicación del pago.

---

# 10. Fechas y zonas horarias

Regla general:

- Persistir timestamps en UTC.
- Convertir a la zona horaria del tenant para presentación y reglas que dependan explícitamente de horario local.

Zona horaria inicial común:

```text
America/Bogota
```

No utilizar la zona horaria del servidor como fuente implícita de reglas de negocio.

---

# 11. Identificadores

Utilizar un estándar consistente para identificadores.

Preferir UUID/ULID o una variante temporalmente ordenable definida por el proyecto.

No mezclar aleatoriamente:

```text
integer
uuid
ulid
```

sin una razón arquitectónica clara.

---

# 12. LPR / ANPR

La integración debe ser independiente del fabricante.

No acoplar el dominio a una cámara específica.

Conceptualmente:

```text
Camera
   ↓
Edge
   ↓
LPR Provider
   ↓
Normalized Plate Event
   ↓
Nuvora Cloud
```

Resultado normalizado:

```json
{
  "event": "plate_detected",
  "plate": "ABC123",
  "confidence": 0.96,
  "camera": "entrada-01",
  "timestamp": "..."
}
```

Conservar cuando sea relevante:

- Placa normalizada.
- Confianza.
- Cámara.
- Carril/dirección.
- Timestamp.
- Evidencia.
- Bounding box.
- Metadata del proveedor.
- Identificador del evento.

Las lecturas repetidas deben deduplicarse mediante una estrategia configurable.

Una lectura de baja confianza no debe convertirse automáticamente en una verdad absoluta.

La retención de imágenes de placas debe ser configurable y limitarse a lo necesario.

---

# 13. Dispositivos

Nuvora debe soportar múltiples fabricantes.

Usar adaptadores.

Ejemplos:

```text
CameraAdapter
├── RTSPCamera
├── ONVIFCamera
├── HttpCamera
├── UsbCamera
└── VendorCamera

GateController
├── HttpGateController
├── SerialGateController
└── VendorGateController
```

No colocar lógica específica de un fabricante en el dominio.

Los eventos provenientes de dispositivos deben ser idempotentes.

---

# 14. Offline y sincronización de Edge

Edge debe poder continuar operando localmente cuando sea técnicamente posible durante una interrupción temporal de Internet.

Usar:

```text
Local Event
    ↓
SQLite spool
    ↓
Cloud unavailable
    ↓
Retry
    ↓
Cloud available
    ↓
Sync
```

Los eventos deben tener identificadores únicos y procesamiento idempotente.

La conexión debe ser preferiblemente saliente desde Edge hacia Cloud.

Evitar exponer directamente Edge a Internet mediante puertos entrantes cuando no sea necesario.

---

# 15. API

La API pública/integración debe versionarse.

Ejemplo:

```text
/api/v1/...
```

Debe definir de forma consistente:

- Autenticación.
- Autorización.
- Validación.
- Errores.
- Paginación.
- Filtros.
- Ordenamiento.
- Idempotencia.
- Rate limiting.
- Webhooks.
- OpenAPI.

No crear endpoints inconsistentes para cada módulo.

---

# 16. Frontend

Stack objetivo:

- Vue 3.
- TypeScript.
- Vite.
- Inertia.js cuando corresponda.
- Tailwind CSS/UI system definido por el proyecto.

TypeScript debe mantenerse estricto.

Evitar:

```typescript
any
```

salvo casos justificados.

Los componentes no deben contener grandes cantidades de lógica empresarial.

Cada pantalla debe considerar:

- Loading.
- Empty state.
- Success.
- Error.
- Validation errors.
- Permission denied.
- Offline state cuando corresponda.
- Responsive behavior.
- Accessibility.

Las acciones destructivas o sensibles requieren confirmación y feedback claro.

---

# 17. Base de datos

Base principal:

```text
PostgreSQL
```

Redis puede utilizarse para:

- Cache.
- Locks.
- Queues.
- Realtime support.
- Rate limiting.
- Datos efímeros.

Las tablas deben tener:

- Foreign keys cuando corresponda.
- Unique constraints.
- Check constraints cuando aporten valor.
- Índices basados en consultas reales.
- `tenant_id` donde corresponda.
- Timestamps consistentes.

No depender exclusivamente de validaciones de aplicación para invariantes que la base de datos pueda garantizar de forma segura.

---

# 18. Transacciones

Las operaciones que modifican múltiples recursos relacionados deben evaluar explícitamente la necesidad de transacciones.

Ejemplos:

- Crear reserva y actualizar disponibilidad.
- Cerrar sesión y registrar cobro.
- Procesar pago.
- Cambiar estado de acceso.
- Registrar evento crítico de dispositivo.
- Aplicar movimientos de caja.

Una transacción no debe mantenerse abierta durante operaciones externas lentas si puede evitarse.

---

# 19. Eventos, jobs y Outbox

Para integraciones y procesos asíncronos, considerar:

- Domain events.
- Integration events.
- Queues.
- Outbox pattern.
- Idempotency keys.

No asumir que:

```text
event sent = event received once
```

Los consumidores deben tolerar:

- Duplicados.
- Reintentos.
- Eventos fuera de orden cuando el dominio lo permita.
- Fallos temporales.
- Payloads inválidos.

---

# 20. Observabilidad

Nuvora debe poder responder:

- ¿Qué ocurrió?
- ¿Cuándo?
- ¿En qué tenant?
- ¿Qué usuario/proceso lo originó?
- ¿Qué request lo produjo?
- ¿Qué dispositivo estuvo involucrado?
- ¿Falló una dependencia externa?
- ¿Cuánto tardó?
- ¿Se procesó nuevamente?

Usar cuando corresponda:

- Structured logging.
- Correlation IDs.
- Metrics.
- Health checks.
- Readiness checks.
- Tracing.
- Error tracking.

Nunca registrar secretos, tokens, contraseñas ni información sensible innecesaria.

---

# 21. Auditoría

Las acciones sensibles deben ser auditables.

Una entrada de auditoría puede incluir:

```text
actor
tenant
action
resource
resource_id
timestamp
ip
user_agent
before
after
correlation_id
```

No registrar información sensible innecesaria.

Los logs técnicos y la auditoría de negocio no son necesariamente lo mismo.

---

# 22. Seguridad

Todo código nuevo debe evaluarse contra:

- Authentication.
- Authorization.
- IDOR.
- Cross-tenant access.
- CSRF.
- XSS.
- SQL injection.
- Mass assignment.
- SSRF.
- Path traversal.
- Unsafe file uploads.
- Secrets exposure.
- Rate limiting.
- Webhook spoofing.
- Replay attacks.
- Insecure direct device access.
- Credential leakage.
- Privilege escalation.

Los archivos subidos deben validarse por:

- Tipo.
- Tamaño.
- Extensión.
- Contenido cuando sea necesario.
- Ubicación de almacenamiento.
- Permisos de acceso.

No asumir que la extensión del archivo garantiza su contenido.

---

# 23. Testing

Cada funcionalidad importante debe incluir pruebas apropiadas.

## Backend

Utilizar Pest/PHPUnit.

Cubrir:

- Casos felices.
- Validaciones.
- Autorización.
- Tenant isolation.
- Reglas de negocio.
- Errores.
- Concurrencia donde sea crítica.
- Idempotencia.
- Integraciones externas.

## Frontend

Utilizar Vitest/Vue Test Utils cuando corresponda.

## E2E

Utilizar Playwright para flujos críticos.

Ejemplos:

- Login.
- Creación de parqueadero.
- Configuración de espacios.
- Creación de tarifa.
- Reserva.
- Check-in.
- Check-out.
- Pago.
- Consulta de ocupación.
- Administración de dispositivos.

## Edge

Probar:

- Cámara desconectada.
- Internet caído.
- Reconexión.
- Evento duplicado.
- Payload inválido.
- Cola local.
- Reintentos.
- Hardware no disponible.

---

# 24. Definition of Done

Una funcionalidad no se considera terminada simplemente porque "funciona".

Debe cumplir, según corresponda:

- [ ] Requerimiento entendido.
- [ ] Diseño revisado.
- [ ] Módulo correcto identificado.
- [ ] Reglas de negocio ubicadas correctamente.
- [ ] Autorización implementada.
- [ ] Tenant isolation verificado.
- [ ] Validaciones implementadas.
- [ ] Persistencia consistente.
- [ ] Concurrencia considerada.
- [ ] Idempotencia considerada.
- [ ] Manejo de errores.
- [ ] Tests relevantes.
- [ ] UI con estados necesarios.
- [ ] Logs/observabilidad cuando sea necesario.
- [ ] Auditoría cuando sea necesario.
- [ ] Documentación actualizada.
- [ ] Migraciones seguras.
- [ ] No existen secretos en el código.
- [ ] Lint/static analysis pasa.
- [ ] Tests pasan.
- [ ] Build pasa.
- [ ] Diff revisado.
- [ ] No hay cambios no relacionados.

---

# 25. Git y commits

Preferir commits pequeños y coherentes.

Formato recomendado:

```text
feat: add reservation availability service
fix: prevent duplicate parking sessions
refactor: isolate tariff calculation
test: cover reservation concurrency
docs: document edge registration flow
chore: update dependencies
```

No mezclar en el mismo commit:

- Feature.
- Refactor masivo.
- Formateo completo del proyecto.
- Cambios no relacionados.

Antes de crear un commit revisar:

```bash
git status
git diff
git diff --staged
```

No realizar commits automáticamente salvo que se solicite.

---

# 26. Pull Requests

Una PR debería explicar:

- Qué cambia.
- Por qué cambia.
- Qué módulos afecta.
- Riesgos.
- Migraciones.
- Cambios de API.
- Cambios de seguridad.
- Tests ejecutados.
- Cambios de configuración.
- Impacto sobre Edge/dispositivos si aplica.

Las PRs deben ser revisables.

---

# 27. Dependencias

Antes de agregar una dependencia:

1. Verificar si el framework ya proporciona la funcionalidad.
2. Evaluar mantenimiento.
3. Evaluar seguridad.
4. Evaluar licencia.
5. Evaluar tamaño/impacto.
6. Evaluar compatibilidad con la arquitectura.
7. Justificar su necesidad.

No introducir librerías para resolver problemas triviales.

---

# 28. Configuración y secretos

Todo secreto debe estar fuera del código fuente.

Usar:

```text
.env
.env.example
secret managers / deployment secrets
```

`.env.example` puede documentar nombres de variables, nunca secretos reales.

Nunca subir:

- API keys.
- Passwords.
- Private keys.
- Tokens.
- Credenciales de cámaras.
- Credenciales de base de datos.

---

# 29. Docker y entorno local

El entorno debe ser reproducible.

Servicios típicos:

```text
app
web
postgres
redis
minio
mailpit
```

Los servicios adicionales solo deben agregarse cuando sean necesarios.

No asumir que producción es idéntica al entorno local.

Las diferencias relevantes deben documentarse.

---

# 30. Documentación

Las decisiones arquitectónicas importantes deben documentarse.

Usar ADRs cuando una decisión:

- Cambia arquitectura.
- Introduce una tecnología relevante.
- Define un contrato.
- Afecta persistencia.
- Cambia seguridad.
- Define una integración con hardware.
- Tiene consecuencias difíciles de revertir.

La documentación debe explicar el "por qué", no solamente repetir el código.

---

# 31. Cómo debe trabajar un agente de IA

Cuando se solicite una tarea:

## Paso 1 — Inspeccionar

Buscar:

- Archivos relevantes.
- Módulo responsable.
- Tests existentes.
- Contratos.
- Migraciones.
- Configuración.
- Dependencias.

## Paso 2 — Comprender

Determinar:

- Qué comportamiento existe.
- Qué comportamiento se necesita.
- Qué invariantes están involucradas.
- Qué módulos se ven afectados.
- Qué riesgos existen.

## Paso 3 — Planificar

Crear un plan breve y concreto antes de realizar cambios grandes.

## Paso 4 — Implementar

Aplicar el cambio siguiendo las reglas del proyecto.

## Paso 5 — Probar

Ejecutar las pruebas relevantes.

## Paso 6 — Revisar

Comprobar:

- Seguridad.
- Tenant isolation.
- Concurrencia.
- Performance.
- Arquitectura.
- Mantenibilidad.

## Paso 7 — Reportar

Indicar:

- Qué se cambió.
- Qué archivos principales se modificaron.
- Qué pruebas se ejecutaron.
- Qué quedó pendiente.
- Cualquier riesgo conocido.

---

# 32. Regla de mínima sorpresa

Si una solución puede implementarse de varias maneras razonables, preferir la que:

- Sea más explícita.
- Sea fácil de probar.
- Sea fácil de mantener.
- Respete los límites de los módulos.
- Sea consistente con el código existente.
- Tenga menor superficie de riesgo.
- Pueda ser entendida por otro desarrollador sin contexto adicional.

---

# 33. Regla final

La prioridad al desarrollar Nuvora es:

```text
Correctness
    ↓
Security
    ↓
Tenant Isolation
    ↓
Data Integrity
    ↓
Business Rules
    ↓
Testability
    ↓
Maintainability
    ↓
Observability
    ↓
Performance
    ↓
Developer Experience
```

No sacrificar seguridad, integridad de datos o reglas de negocio por velocidad de implementación.

Nuvora debe evolucionar como un producto profesional, no como una colección de CRUDs independientes.
